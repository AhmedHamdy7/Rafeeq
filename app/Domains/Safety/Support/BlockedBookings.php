<?php

namespace App\Domains\Safety\Support;

use App\Domains\Booking\Actions\CancelBookingAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Support\DriverFees;
use App\Domains\Payment\Support\FeeSettings;
use App\Domains\Payment\Support\PassengerFees;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Trip\Enums\WarningKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The bookings a block ends (Feature 03 files X-4, decision D5, SAFE-15).
 *
 * > *"Blocking cancels every upcoming booking between the two, charged to the blocker under the
 * > normal rules … The blocked person is only told a booking was cancelled."*
 *
 * 🔴 **One query for the preview and the block**, so what the screen says will be cancelled is what
 * is cancelled. "Upcoming" is a CONFIRMED seat on a day that has not set off — the only seats that
 * can still put the two of them in one car. A run already under way is not touched: a block is
 * not a way to end a journey somebody is sitting in, and the car has its own exits for that.
 *
 * 🔴 **Cancelled through the ordinary paths, as the blocker** — her own seat through
 * {@see CancelBookingAction::byPassenger()}, a passenger's seat on his commute through
 * {@see CancelBookingAction::byDriver()} — so the normal rules apply unchanged: the seat goes back
 * to the waiting list, the reliability record takes the ordinary notice-based mark, and whatever
 * fee those rules charge is charged (PAY-21, PAY-23).
 *
 * 🔒 **No reason is written on the booking.** `cancelledReason` is returned to BOTH sides, so
 * writing "blocked" there would tell the blocked person exactly what a block must never tell them.
 * They get the same "your booking was cancelled" as any other cancellation, and nothing more.
 */
final readonly class BlockedBookings
{
    public function __construct(private CancelBookingAction $cancel) {}

    /**
     * Every confirmed seat on a day not yet under way that has these two in the same car, in
     * departure order.
     *
     * @return Collection<int, Booking>
     */
    public static function between(User $blocker, string $otherUserId): Collection
    {
        return Booking::query()
            ->where('status', BookingStatus::Confirmed->value)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('passenger_user_id', $blocker->id)->where('driver_profile_id', $otherUserId))
                ->orWhere(fn ($q) => $q->where('passenger_user_id', $otherUserId)->where('driver_profile_id', $blocker->id)))
            ->whereHas('scheduledTrip', fn ($query) => $query
                ->where('status', ScheduledTripStatus::Scheduled->value))
            ->with('scheduledTrip')
            ->get()
            ->sortBy(fn (Booking $booking): string => $booking->scheduledTrip?->departure_at?->toIso8601String().$booking->id)
            ->values();
    }

    /**
     * What blocking this person would do, before she decides (`GET /safety/blocked-users/preview`).
     *
     * @return array{bookingsToCancel: list<array{bookingId: string, tripId: string, departureAt: string|null, role: string}>, feePiastres: int, reason: string|null}
     */
    public static function preview(User $blocker, string $otherUserId): array
    {
        $bookings = self::between($blocker, $otherUserId);

        return [
            'bookingsToCancel' => $bookings->map(fn (Booking $booking): array => [
                'bookingId' => (string) $booking->id,
                'tripId' => (string) $booking->scheduled_trip_id,
                'departureAt' => $booking->scheduledTrip?->departure_at?->toIso8601String(),
                // Which side of this booking the blocker is on — whose cancellation it will be.
                'role' => $booking->passenger_user_id === $blocker->id ? 'passenger' : 'driver',
            ])->all(),
            /*
             * What the ordinary rules would charge the blocker for these (X-4): as a driver, the
             * driver-cancel fee by notice per seat (PAY-23); as a passenger, a late cancel within
             * 12 hours on her own counter (PAY-21) — which is dropped if the seat is refilled, so
             * this is the most it can cost.
             */
            'feePiastres' => $fee = self::fee($blocker, $bookings),
            // `none`, `driver_cancel` or `late_cancel` — what the fee is for, to word it.
            'reason' => match (true) {
                $fee === 0 => null,
                $bookings->contains(fn (Booking $b) => $b->driver_profile_id === $blocker->id && DriverFees::cancelPercent($b->scheduledTrip?->departure_at) > 0) => 'driver_cancel',
                default => 'late_cancel',
            },
        ];
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private static function fee(User $blocker, Collection $bookings): int
    {
        $fee = 0;
        $rung = PassengerFees::nextRung((string) $blocker->id, WarningKind::LateCancel, CarbonImmutable::now());

        foreach ($bookings as $booking) {
            $departure = $booking->scheduledTrip?->departure_at;

            if ($booking->driver_profile_id === $blocker->id) {
                $fee += DriverFees::amount($booking, DriverFees::cancelPercent($departure));

                continue;
            }

            if ($departure !== null && now()->diffInHours($departure, absolute: false) < FeeSettings::lateCancelHours()) {
                $fee += PassengerFees::amountPiastres($booking, PassengerFees::basisPoints(WarningKind::LateCancel, $rung++));
            }
        }

        return $fee;
    }

    /**
     * Cancels them, as the blocker. Returns how many were cancelled.
     */
    public function cancelAll(User $blocker, string $otherUserId): int
    {
        $cancelled = 0;

        foreach (self::between($blocker, $otherUserId) as $booking) {
            try {
                if ($booking->passenger_user_id === $blocker->id) {
                    $this->cancel->byPassenger($booking);
                } else {
                    $this->cancel->byDriver($booking, $blocker->id);
                }
            } catch (DomainException) {
                // It stopped being cancellable between the read and now (the run set off, the other
                // side cancelled first). Nothing to do for that one; the block still stands.
                continue;
            }

            $cancelled++;
        }

        return $cancelled;
    }
}
