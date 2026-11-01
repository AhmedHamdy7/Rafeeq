<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Actions\AcceptSeatOfferAction;
use App\Domains\Booking\Actions\ApproveSeatRequestAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 🔴 The per-day double-booking guard: one person, one car at a time (BOOK-17, Feature 03 R-3).
 *
 * The unique `bookings.live_booking_key` is per TRIP — `scheduled_trip_id:passenger_user_id` — so
 * it stops two seats on the same morning of the same commute and nothing else. Two different
 * drivers could each approve her for 07:05 on Sunday and she would hold two confirmed seats, one of
 * them a car that waits outside her building for somebody who is in another car. This class is the
 * rule that closes that gap, asked from every place a seat is given: a driver's approval
 * ({@see ApproveSeatRequestAction}), a waiting-list acceptance ({@see AcceptSeatOfferAction}) and
 * the nightly recurring extension.
 *
 * **"Same time", not "same date".** A commuter rides to work at 07:05 and home at 17:00 — two
 * bookings on one date, on two commutes, and both legitimate (D-0019: several commutes on one day
 * when they do not overlap). So two trips clash when their windows overlap, where a window runs
 * from the departure to the promised arrival, or — when no arrival was promised — to the departure
 * plus the planned duration of the day's route.
 *
 * **Why a database constraint cannot do it.** "Overlaps in time" is not an equality a unique index
 * can express. So {@see assertNone()} first takes a row lock on HER user row: two approvals for the
 * same passenger on different commutes hold locks on different trips and would otherwise both read
 * "no overlap" and both write. With the user row locked they queue, and the second one's locking
 * read sees the first one's booking. Every caller already holds its trip locks by then, and none of
 * them takes a trip lock after this one, so the order is always trips → passenger.
 *
 * A switch is the one deliberate exception: the booking she is leaving in the same transaction is
 * passed in `$except`, because for an instant both exist and that instant is the switch itself.
 */
final class SameTimeBookings
{
    /**
     * Statuses that hold a seat. The same three the live-booking index counts, so the two rules
     * cannot disagree about what "has a seat" means.
     */
    private const array LIVE = [
        BookingStatus::Pending->value,
        BookingStatus::Confirmed->value,
        BookingStatus::Completed->value,
    ];

    /**
     * Refuse a seat that would overlap one she already holds on another trip.
     *
     * MUST be called inside the transaction that will create the booking, after the trip locks.
     *
     * @param  array<int, string>  $except  booking ids she is giving up in the same transaction
     */
    public static function assertNone(ScheduledTrip $trip, string $passengerUserId, array $except = []): void
    {
        // The queue: see the class note on why this lock and not a constraint.
        User::query()->whereKey($passengerUserId)->lockForUpdate()->first();

        $clash = self::overlapping($trip, $passengerUserId, $except, locking: true)->first();

        if ($clash !== null) {
            throw DomainException::of(ErrorCode::AlreadyBooked, fields: [
                // Her own booking, so she may be told which one. Never shown to a driver.
                'overlapsBookingId' => [(string) $clash->getKey()],
            ]);
        }
    }

    /**
     * Her live bookings on OTHER trips whose window overlaps this trip's.
     *
     * @param  array<int, string>  $except
     * @return Collection<int, Booking>
     */
    public static function overlapping(
        ScheduledTrip $trip,
        string $passengerUserId,
        array $except = [],
        bool $locking = false,
    ): Collection {
        [$start, $end] = self::window($trip);

        /*
         * Narrowed in SQL to a day either side before the exact comparison: a trip that crosses
         * midnight is the only way a booking on another date can overlap, and a day is far longer
         * than any commute.
         */
        $candidates = Booking::query()
            ->where('passenger_user_id', $passengerUserId)
            ->whereIn('status', self::LIVE)
            ->where('scheduled_trip_id', '!=', $trip->getKey())
            ->when($except !== [], fn ($query) => $query->whereNotIn('id', $except))
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->whereBetween('departure_at', [$start->subDay(), $end->addDay()])
                ->select('id'))
            ->with('scheduledTrip.commuteOffer')
            ->orderBy('id')
            // A locking read sees what other transactions have committed since this one began —
            // which is the whole point of having queued behind them on her user row.
            ->when($locking, fn ($query) => $query->sharedLock())
            ->get();

        return $candidates
            ->filter(fn (Booking $booking): bool => $booking->scheduledTrip !== null
                && self::overlaps($trip, $booking->scheduledTrip))
            ->values();
    }

    /**
     * Whether two days' windows overlap. Touching end to start (arrive 08:00, next one leaves
     * 08:00) does not count.
     */
    public static function overlaps(ScheduledTrip $a, ScheduledTrip $b): bool
    {
        [$aStart, $aEnd] = self::window($a);
        [$bStart, $bEnd] = self::window($b);

        return $aStart->lessThan($bEnd) && $bStart->lessThan($aEnd);
    }

    /**
     * From the departure to the arrival.
     *
     * The promised arrival where there is one, because it is what the driver said; otherwise the
     * departure plus the day's measured itinerary, then the commute's planned route duration —
     * the same order `AutoFinishTripsAction` reads them in. A trip with none of the three is a
     * moment rather than a window, and still clashes with anything that departs before and
     * arrives after it.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(ScheduledTrip $trip): array
    {
        $start = CarbonImmutable::parse($trip->departure_at);

        if ($trip->promised_arrival_at !== null) {
            $end = CarbonImmutable::parse($trip->promised_arrival_at);
        } else {
            $seconds = $trip->itinerary_duration_seconds
                ?? ($trip->relationLoaded('commuteOffer') ? $trip->commuteOffer?->route_duration_seconds : null)
                ?? 0;

            $end = $start->addSeconds((int) $seconds);
        }

        // A promise earlier than the departure is bad data, not a negative window.
        return [$start, $end->greaterThan($start) ? $end : $start->addSecond()];
    }
}
