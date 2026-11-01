<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Actions\ApproveSeatRequestAction;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestKind;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Collection;

/**
 * When a pending seat request stops being a request for a seat and becomes an application to the
 * waiting list (Feature 03 R-1, R-3; BOOK-18, BOOK-34, BOOK-36).
 *
 * Requests never expire any more (R-4), so a request has to be able to change what it is asking
 * for without anybody re-sending it. Two things do that, and both are here so the rule is in one
 * place:
 *
 * 1. **Another driver confirmed her for the same time** (BOOK-34). "When one confirms, the other
 *    pending requests become waiting-list entries on those commutes, and those drivers see the
 *    person as wanting to join their waiting list." She cannot ride two cars at once
 *    ({@see SameTimeBookings}), so a "yes" from the second driver could no longer seat her — but it
 *    can put her on his list, which is exactly what she would want if the first commute falls
 *    through.
 * 2. **The day she asked for filled up** while her one-day request waited. "A request stays until
 *    accepted, rejected, withdrawn by her, or the seat is filled" (R-4): once nobody can approve it
 *    into a seat, it is a waiting-list application, and the driver's inbox should say so.
 *
 * Both keep the status `pending`. It is still the driver's turn — he decides whether she joins his
 * list (BOOK-18) — and nothing she sent changes. Only the question does.
 *
 * Never the other way: a seat freeing up does not turn an application back into a seat request.
 * The freed seat is offered to the waiting list (BOOK-20), and she gets it from there once the
 * driver has approved her onto it.
 */
final class RequestKinds
{
    /**
     * What a NEW request is asking for (Feature 03 R-1, BOOK-17, BOOK-18, BOOK-33).
     *
     * - **One day** (`trial`): an application when that day is full, or when she already holds a
     *   seat at an overlapping time on another commute — the server never gives her two
     *   overlapping seats (BOOK-17), so the only thing that commute can offer her is its list.
     * - **Recurring**: an application when NONE of the upcoming days she asked for could seat her
     *   — each one full, or overlapping a seat she holds. A pattern with one free Tuesday is still
     *   a seat request: approval seats what it can and names the days it skipped, exactly as it
     *   always has, and turning that into "waiting list" would take a real seat away from her.
     *
     * Read without locks. It decides which question the driver is shown, not who gets a seat;
     * that is settled under the trip locks when he answers.
     */
    public static function atRequest(
        CommuteOffer $offer,
        ?ScheduledTrip $trip,
        SeatRequestCommitment $commitment,
        ?int $requestedDaysMask,
        int $seats,
        string $passengerUserId,
    ): SeatRequestKind {
        if ($commitment === SeatRequestCommitment::Trial) {
            if ($trip === null) {
                return SeatRequestKind::SeatRequest;
            }

            return self::cannotSeat($trip, $seats, $passengerUserId)
                ? SeatRequestKind::WaitlistApplication
                : SeatRequestKind::SeatRequest;
        }

        $days = self::upcomingDays($offer->getKey(), $requestedDaysMask);

        if ($days->isEmpty()) {
            // Nothing generated yet to judge by. Asked as a seat; approval says what it finds.
            return SeatRequestKind::SeatRequest;
        }

        foreach ($days as $day) {
            if (! self::cannotSeat($day, $seats, $passengerUserId)) {
                return SeatRequestKind::SeatRequest;
            }
        }

        return SeatRequestKind::WaitlistApplication;
    }

    /**
     * Full, or she already rides somewhere else at that time.
     */
    private static function cannotSeat(ScheduledTrip $trip, int $seats, string $passengerUserId): bool
    {
        return $trip->seats_taken + $seats > $trip->seats_total
            || SameTimeBookings::overlapping($trip, $passengerUserId)->isNotEmpty();
    }

    /**
     * The days a recurring request would be seated on: the same horizon and weekday filter
     * {@see ApproveSeatRequestAction} uses, so "could she be seated" is asked of the same days.
     *
     * @return Collection<int, ScheduledTrip>
     */
    private static function upcomingDays(string $offerId, ?int $requestedDaysMask): Collection
    {
        $mask = $requestedDaysMask === null ? null : DaysMask::fromBits($requestedDaysMask);

        return ScheduledTrip::query()
            ->with('commuteOffer')
            ->where('commute_offer_id', $offerId)
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->where('departure_at', '<=', now()->addDays((int) config('rafeeq.booking.recurring_horizon_days')))
            ->orderBy('departure_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (ScheduledTrip $trip): bool => $mask === null || $mask->includesDate($trip->trip_date))
            ->values();
    }

    /**
     * BOOK-34: she was just confirmed on these bookings, so her other pending seat requests that
     * overlap them become waiting-list applications.
     *
     * @param  iterable<int, Booking>  $bookings  the bookings just created, each with its trip
     * @return int how many requests changed
     */
    public static function afterConfirmation(string $passengerUserId, iterable $bookings): int
    {
        $tripIds = [];

        foreach ($bookings as $booking) {
            $tripIds[] = (string) $booking->scheduled_trip_id;
        }

        // Re-read with their commutes rather than trusting whatever the caller had loaded: the
        // overlap window falls back to the commute's planned duration.
        $trips = ScheduledTrip::query()
            ->with('commuteOffer')
            ->whereIn('id', array_unique($tripIds))
            ->orderBy('id')
            ->get();

        if ($trips->isEmpty()) {
            return 0;
        }

        $others = SeatRequest::query()
            ->where('passenger_user_id', $passengerUserId)
            ->where('status', SeatRequestStatus::Pending->value)
            ->where('kind', SeatRequestKind::SeatRequest->value)
            // A three-minute question about a moving car is left to run out on its own.
            ->where('requested_during_ride', false)
            ->whereNotIn('commute_offer_id', $trips->pluck('commute_offer_id')->unique()->all())
            ->orderBy('id')
            ->get();

        $changed = 0;

        foreach ($others as $request) {
            if (self::clashesWith($request, $trips)) {
                $request->forceFill(['kind' => SeatRequestKind::WaitlistApplication->value])->save();
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * R-4's "or the seat is filled": once a day is full, a one-day seat request for it can only be
     * an application to the waiting list.
     *
     * A during-ride request is left alone — it is a question about a car that is moving, with three
     * minutes to answer, and a waiting list for a run that ends in forty minutes means nothing.
     *
     * @param  iterable<int, ScheduledTrip>  $trips  days that just had seats taken
     */
    public static function afterSeatsTaken(iterable $trips): int
    {
        $full = [];

        foreach ($trips as $trip) {
            if ($trip->seats_taken >= $trip->seats_total) {
                $full[] = (string) $trip->getKey();
            }
        }

        if ($full === []) {
            return 0;
        }

        return SeatRequest::query()
            ->whereIn('scheduled_trip_id', $full)
            ->where('status', SeatRequestStatus::Pending->value)
            ->where('kind', SeatRequestKind::SeatRequest->value)
            // One-day requests only: a recurring one is about a pattern, and one full day of it
            // is a day approval skips, not a reason to stop asking for the rest.
            ->where('commitment', SeatRequestCommitment::Trial->value)
            ->where('requested_during_ride', false)
            ->update(['kind' => SeatRequestKind::WaitlistApplication->value]);
    }

    /**
     * Whether this request asks for a day that overlaps one of these confirmed trips.
     *
     * A one-day request names its day. A recurring one names a pattern, so it is asked on the
     * dates she was just confirmed for: does this other commute run that day, on a weekday she
     * asked for, at an overlapping time?
     *
     * @param  Collection<int, ScheduledTrip>  $confirmed
     */
    private static function clashesWith(SeatRequest $request, Collection $confirmed): bool
    {
        if ($request->commitment === SeatRequestCommitment::Trial) {
            $asked = ScheduledTrip::query()->with('commuteOffer')->find($request->scheduled_trip_id);

            return $asked !== null && $confirmed->contains(
                fn (ScheduledTrip $trip): bool => SameTimeBookings::overlaps($asked, $trip)
            );
        }

        $mask = $request->requested_days_mask === null ? null : DaysMask::fromBits($request->requested_days_mask);

        $candidates = ScheduledTrip::query()
            ->with('commuteOffer')
            ->where('commute_offer_id', $request->commute_offer_id)
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->whereIn('trip_date', $confirmed->map(fn (ScheduledTrip $trip) => $trip->trip_date->toDateString())->unique()->all())
            ->orderBy('id')
            ->get()
            ->filter(fn (ScheduledTrip $trip): bool => $mask === null || $mask->includesDate($trip->trip_date));

        foreach ($candidates as $candidate) {
            foreach ($confirmed as $trip) {
                if (SameTimeBookings::overlaps($candidate, $trip)) {
                    return true;
                }
            }
        }

        return false;
    }
}
