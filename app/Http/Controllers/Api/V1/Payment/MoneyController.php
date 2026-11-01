<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The group's money, as the two people in the car see it (Bible §8, Chapter 7).
 *
 * 🔴 The passenger pays the seat price; RAFEEQ's commission is on top of the driver's share
 * (PAY-48), frozen on each booking. On a CASH trip the driver collects the whole seat at the
 * roadside, so the commission becomes a debt she owes RAFEEQ — see `GET /account/debt`
 * ({@see DebtController}), which replaced `GET /driver/balance`.
 *
 * 🔒 Nothing here moves money.
 */
final class MoneyController extends Controller
{
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
