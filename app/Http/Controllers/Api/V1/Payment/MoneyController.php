<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Payment\Models\DriverBalance;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\DriverBalanceResource;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The money, as the two people in the car see it (Bible §8, Chapter 7).
 *
 * 🔴 The decision this whole controller rests on, settled 2026-10-06: **the platform's fee is
 * deducted from the DRIVER, at 3%, and the passenger pays the seat price.** The Bible's own worked
 * example showed a passenger paying 8800 for an 8000 seat, which is the other reading and was
 * rejected — so an 8000 seat means the passenger owes 8000 and the driver keeps 7760.
 *
 * 🔴 And the consequence the screens exist to make visible: on a CASH trip the driver collects the
 * whole 8000 at the roadside, so the 240 is not withheld from her — it becomes a **debt she owes
 * us**. It accrues trip by trip and eventually stops her publishing. A driver who is only ever
 * shown "earnings" and then cannot publish has been told nothing.
 *
 * 🔒 What is deliberately NOT here: anything that moves money. No payment method, no capture, no
 * payout, no refund. Those need a provider that has not been chosen, and a half-built capture path
 * is worse than none — see section 7 of the mobile guide.
 */
final class MoneyController extends Controller
{
    /**
     * GET /v1/driver/balance — what she owes and what she has earned.
     *
     * 🔒 Her own, always. There is no endpoint at any access level that reads another driver's
     * balance: it is her income and her debt, and the only other party entitled to see it is the
     * dashboard, which authenticates separately.
     */
    public function driverBalance(Request $request): JsonResponse
    {
        /*
         * `firstOrNew`, because a driver who has never completed a cash trip has no row yet — and
         * the honest answer for her is zeros, not a 404. A 404 would read as "no such driver".
         */
        $balance = DriverBalance::query()
            ->whereKey($request->user()->id)
            ->first() ?? new DriverBalance;

        return ApiResponse::success(new DriverBalanceResource($balance));
    }

    /**
     * GET /v1/groups/{group}/statement — the group's week, screen 20's `payments` tab.
     *
     * One row per journey with what it cost and whether it is settled, plus the totals. Which rows
     * a caller sees depends on who they are, and that is the only interesting decision here:
     *
     * - **the driver** sees every passenger's row, because she is the one collecting the cash and
     *   cannot reconcile a week she can only see part of;
     * - **a passenger** sees only her own rows. What her fellow passengers pay is none of her
     *   business — they may be on different prices after a price change, and a group statement that
     *   exposed that would turn a shared commute into a negotiation.
     *
     * 🔒 So this is not one payload filtered in the client. The query itself differs.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function groupStatement(Request $request, string $group): JsonResponse
    {
        $user = $request->user();

        $commuteGroup = CommuteGroup::query()
            ->whereKey($group)
            ->with('commuteOffer')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $isDriver = $commuteGroup->commuteOffer?->driver_profile_id === $user->id;

        if (! $isDriver && ! $commuteGroup->members()->where('user_id', $user->id)->exists()) {
            // 404-shaped: whether a particular group exists is not learnable by asking for its
            // money.
            throw DomainException::of(ErrorCode::NotFound);
        }

        [$from, $to] = $this->week($request);

        $bookings = Booking::query()
            ->where('commute_group_id', $commuteGroup->id)
            // Cancelled and expired rows are not money; they are things that did not happen.
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Completed, BookingStatus::NoShow])
            ->when(! $isDriver, fn ($query) => $query->where('passenger_user_id', $user->id))
            ->whereHas('scheduledTrip', fn ($query) => $query
                ->whereBetween('trip_date', [$from->toDateString(), $to->toDateString()]))
            ->with(['scheduledTrip', 'passenger'])
            ->get();

        $rows = [];
        $due = 0;
        $paid = 0;
        $feesOwed = 0;

        foreach ($bookings as $booking) {
            $isPaid = $booking->payment_status === PaymentStatus::Paid;

            /*
             * 🔴 Read from the booking's SNAPSHOT columns, never recomputed from the commute's
             * current price. That is the entire reason those columns exist: a driver who raises her
             * price must not retroactively change what somebody already agreed to pay, and a
             * statement that recalculated would show a week that never happened.
             */
            $rows[] = [
                'bookingId' => $booking->id,
                'tripDate' => $booking->scheduledTrip?->trip_date,
                // The driver needs to know who; a passenger looking at her own rows does not.
                'passengerFirstName' => $isDriver ? $booking->passenger?->public_first_name : null,
                'seats' => (int) $booking->seats_reserved,
                'pricePiastres' => (int) $booking->price_snapshot_piastres,
                // Shown to the driver only: it is her fee, out of her share.
                'platformFeePiastres' => $isDriver ? (int) $booking->platform_fee_snapshot_piastres : null,
                'driverKeepsPiastres' => $isDriver ? (int) $booking->driver_amount_snapshot_piastres : null,
                'paymentType' => $booking->payment_type->value,
                'paymentStatus' => strtoupper($booking->payment_status->value),
                /*
                 * A no-show is still owed, per decision D18 — the seat was held and the car went.
                 * Flagged so the screen can show it differently rather than as an ordinary ride.
                 */
                'wasNoShow' => $booking->status === BookingStatus::NoShow,
            ];

            $due += (int) $booking->price_snapshot_piastres;

            if ($isPaid) {
                $paid += (int) $booking->price_snapshot_piastres;
            }

            if ($isDriver) {
                $feesOwed += (int) $booking->platform_fee_snapshot_piastres;
            }
        }

        return ApiResponse::success([
            'weekStart' => $from->toDateString(),
            'weekEnd' => $to->toDateString(),
            'asDriver' => $isDriver,

            'totals' => [
                'duePiastres' => $due,
                'paidPiastres' => $paid,
                // Stated rather than left as subtraction, because this is the number the screen is
                // opened to find.
                'outstandingPiastres' => $due - $paid,
                /*
                 * The driver's own fee for the week, and her take-home after it. Null for a
                 * passenger: it is not her money and not her concern.
                 */
                'platformFeePiastres' => $isDriver ? $feesOwed : null,
                'driverKeepsPiastres' => $isDriver ? $due - $feesOwed : null,
            ],

            'rows' => $rows,
        ]);
    }

    /**
     * The week being asked about — Saturday to Friday, the Egyptian week.
     *
     * 🔴 Saturday, not Monday. The platform's day bitmask starts at Saturday (Sat=1 … Fri=64) and
     * the reference commute runs Sunday to Thursday, so a Monday-start week would split every
     * working week across two statements and make the totals meaningless.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function week(Request $request): array
    {
        $anchor = $request->date('weekOf') ?? now();

        $from = CarbonImmutable::parse($anchor)->startOfWeek(CarbonImmutable::SATURDAY);

        return [$from, $from->addDays(6)];
    }
}
