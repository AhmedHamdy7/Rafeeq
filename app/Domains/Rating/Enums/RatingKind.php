<?php

namespace App\Domains\Rating\Enums;

/**
 * What a rating is an opinion about (Feature 03 v5).
 *
 * > *"Passenger affected by **canceled confirmed booking** may leave rating/comment even if no ride
 * > occurred. Display as **Canceled Booking Feedback**, distinct from completed-ride review;
 * > visible immediately."*
 *
 * 🔴 The two kinds behave differently in three ways, and each difference follows from the same
 * fact — that one of them has no counterpart rating:
 *
 * 1. **Double-blind applies to a completed ride and not to a cancellation.** The hidden-until-both
 *    -rated rule exists to stop retaliation: she must not be able to read what he wrote before
 *    answering it. A cancelled booking has nothing to retaliate against, because the driver cannot
 *    rate a passenger for a journey that did not happen — so there is no pair to reveal, and
 *    holding the feedback back would only delay it for no one's protection.
 * 2. **A cancellation can be edited while it is visible.** The ordinary rule refuses an edit the
 *    moment a rating becomes readable, because "rate five stars, wait for the reveal, read hers,
 *    revise mine to one" would otherwise be two legitimate calls. With nothing to read, that attack
 *    does not exist, and the edit window goes back to being what it is for: fixing what you wrote.
 * 3. **It does not move the star average.** See {@see self::countsTowardsAverage()}.
 */
enum RatingKind: string
{
    /** The ordinary review of a journey that happened. Double-blind, and counts. */
    case CompletedRide = 'completed_ride';

    /**
     * A passenger's feedback on a confirmed seat the driver cancelled — including one taken by
     * pausing a commute or replacing a vehicle, which are recorded as driver cancellations.
     */
    case CanceledBooking = 'canceled_booking';

    /** Whether the rating has to wait for the other side before anybody may read it. */
    public function isDoubleBlind(): bool
    {
        return $this === self::CompletedRide;
    }

    /**
     * Whether these stars belong in the person's published average.
     *
     * 🔴 **They do not, and this is a reading of v5 rather than a quotation of it.** v5 says to
     * display this feedback "distinct from completed-ride review" and gives cancellations their own
     * instrument: *"Cancellation/displacement reduces Reliability Score even when fee paid."* The
     * star average answers "what is she like to ride with", and a cancellation is not a ride — so
     * folding it in would both answer that question with evidence about something else and punish
     * the same event twice, once in the score built for it and once in the mean.
     *
     * The stars are not wasted: they are what the feedback is shown with, under its own heading, on
     * the profile of somebody deciding whether to rely on her.
     */
    public function countsTowardsAverage(): bool
    {
        return $this === self::CompletedRide;
    }
}
