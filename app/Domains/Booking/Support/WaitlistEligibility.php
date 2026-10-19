<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Actions\AcceptSeatOfferAction;
use App\Domains\Booking\Actions\OfferSeatsToWaitlistMembersAction;
use App\Domains\Booking\Actions\OfferSeatToWaitlistAction;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who on a waiting list can be offered which day — asked from both directions.
 *
 * 🔴 This exists because the question is now asked twice, and the two answers must not drift.
 *
 *   - a seat frees on one day, so **who is waiting for it?** ({@see OfferSeatToWaitlistAction})
 *   - the driver picks one waiting person on resume, so **which days can she be offered?**
 *     ({@see OfferSeatsToWaitlistMembersAction})
 *
 * Written as two queries over one predicate rather than two independent `where` chains. A rule that
 * lived in both places would eventually accept somebody from one direction and refuse her from the
 * other, and the symptom would be a passenger who can see an offer she cannot take.
 *
 * The predicate, in words: she is still waiting, the day is one she asked for, she needs no more
 * seats than are free, and nobody has answered her with a deadline that has passed.
 */
final class WaitlistEligibility
{
    /**
     * The people waiting who could take this day, in the order they should be TOLD.
     *
     * @param  int  $seatsFree  how many seats are actually available on the day
     * @return Builder<SeatRequest>
     */
    public static function requestsForTrip(ScheduledTrip $trip, int $seatsFree): Builder
    {
        return SeatRequest::query()
            ->where('commute_offer_id', $trip->commute_offer_id)
            ->where('status', SeatRequestStatus::Waitlisted->value)
            /*
             * A trial waiting for Tuesday is not helped by a seat freeing on Wednesday. A recurring
             * request (no single day) wants every day it committed to, so it counts.
             */
            ->where(fn (Builder $q) => $q
                ->whereNull('scheduled_trip_id')
                ->orWhere('scheduled_trip_id', $trip->getKey()))
            // Somebody who needs two seats is not served by one freeing.
            ->where('seats', '<=', $seatsFree)
            ->where(fn (Builder $q) => self::stillWaiting($q))
            /*
             * Ordered by position even though the seat is a race. The order decides who is TOLD
             * first, which is the only advantage a position can honestly confer once acceptance is
             * first-come — and it keeps the list's displayed order meaning something.
             */
            ->orderBy('waitlist_position')
            ->orderBy('id');
    }

    /**
     * The days this one waiting person could be offered, soonest first.
     *
     * Only days that have not started and still have room. A commute that resumes on Wednesday has
     * Monday and Tuesday in its past; offering those would be offering a lift that has already not
     * happened.
     *
     * 🔴 `seats_taken + seats <= seats_total` is checked here so the driver is not shown an offer
     * that cannot be honoured — but it is NOT what makes the allocation safe. That happens under
     * the trip's row lock when somebody accepts ({@see AcceptSeatOfferAction}),
     * because between this query and her tap the seat can go to anybody.
     *
     * @return Builder<ScheduledTrip>
     */
    public static function tripsForRequest(SeatRequest $request): Builder
    {
        return ScheduledTrip::query()
            ->where('commute_offer_id', $request->commute_offer_id)
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->whereRaw('seats_taken + ? <= seats_total', [$request->seats])
            ->when(
                $request->scheduled_trip_id !== null,
                // A trial asked for one specific day and wants no other.
                fn (Builder $q) => $q->whereKey($request->scheduled_trip_id),
            )
            ->orderBy('departure_at')
            // Standard #42: a tiebreak, so two days departing at the same minute order the same way
            // every time this runs.
            ->orderBy('id');
    }

    /**
     * Still waiting for a real answer; an expired request is not a person still hoping.
     *
     * @param  Builder<SeatRequest>  $query
     */
    private static function stillWaiting(Builder $query): void
    {
        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
    }
}
