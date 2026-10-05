<?php

namespace App\Domains\Matching\Support;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stage one of the search: everything a passenger is not allowed to see, removed
 * in SQL before a single score is computed.
 *
 * 🔴 This class is the whole reason pitfall #15 cannot happen. A women-only
 * commute must EXCLUDE non-women, never score them lower — a man appearing in a
 * women-only group's results with six points out of ten instead of ten is a
 * security breach, not a ranking quirk. Exclusion lives in the query, so no
 * scoring change downstream can ever reintroduce it, and no future caller can
 * forget to apply it.
 *
 * It is also what makes the search affordable. The Bible's figures: roughly
 * 50,000 published trips narrowed to about 800 in ~15ms, using only indexed
 * integer and boolean comparisons — no geography, no provider calls. The
 * expensive stages then run over hundreds of rows instead of tens of thousands.
 */
final class HardFilters
{
    /**
     * @return Builder<ScheduledTrip>
     */
    public static function apply(Builder $query, User $passenger, SearchCriteria $criteria): Builder
    {
        return $query
            ->whereHas('commuteOffer', function (Builder $offer) use ($passenger, $criteria): void {
                self::applyEligibility($offer, $passenger);

                // Relevance, as opposed to eligibility: whether this journey is
                // anywhere near the one being searched for.
                self::intersectBoundingBox($offer, $criteria);

                self::requireMinimumRating($offer, $criteria);
            })
            // Only days that can still be booked. A trip whose deadline has
            // passed is not a match, it is a disappointment with a nice score.
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->where(fn (Builder $trip) => $trip
                ->whereNull('booking_deadline_at')
                ->orWhere('booking_deadline_at', '>', now()))
            // Seats the passenger actually needs, compared column to column so
            // the database does the arithmetic and the index still applies.
            ->whereRaw('(seats_total - seats_taken) >= ?', [$criteria->seatsNeeded])
            ->whereHas('commuteSchedule', fn (Builder $schedule) => $schedule
                ->whereRaw('(days_mask & ?) > 0', [$criteria->days->value]));
    }

    /**
     * 🔴 WHO may ride with this driver — separately from what is relevant to a
     * search.
     *
     * The split matters because these rules apply wherever a passenger meets a
     * commute, not only in search results. Requesting a seat calls this with an
     * offer id that might have arrived any way at all; if eligibility lived only
     * inside the search query, a man could request a seat on a women-only commute
     * by calling that endpoint directly. One implementation means the two cannot
     * drift apart.
     *
     * @param  Builder<CommuteOffer>  $offer
     */
    public static function applyEligibility(Builder $offer, User $passenger): Builder
    {
        $offer->where('status', CommuteOfferStatus::Published->value);

        self::excludeForbiddenAudience($offer, $passenger);
        self::requireApprovedDriver($offer);
        self::excludeBlockedEitherWay($offer, $passenger);
        self::requireTrustLevel($offer, $passenger);

        return $offer;
    }

    /**
     * 🔴 The hard audience filter. A commute open to any verified member is
     * always allowed; a women-only one only to women.
     *
     * `gender` is read from the passenger, never from the request: a filter that
     * trusted a submitted value would be no filter at all.
     */
    private static function excludeForbiddenAudience(Builder $offer, User $passenger): void
    {
        if ($passenger->gender === Gender::Woman) {
            // A woman may see both kinds, so there is nothing to exclude.
            return;
        }

        $offer->where('audience', CommuteAudience::AnyVerified->value);
    }

    /**
     * A commute whose driver has since been suspended, or whose licence has
     * lapsed, is not bookable however good its score. Checked here rather than
     * relying on the offer having been paused, because the offer and the driver
     * can fall out of step for as long as it takes a sweep to run.
     *
     * 🔴 "Suspended" means two different things and both are checked: the driver
     * PROFILE (a driving decision) and the ACCOUNT (a staff decision about the person,
     * Phase 13). Checking only the first left a suspended driver's commutes in search
     * results — passengers could request seats from somebody who could no longer
     * answer, approve or start anything.
     */
    private static function requireApprovedDriver(Builder $offer): void
    {
        $offer->whereHas('driverProfile', fn (Builder $driver) => $driver
            ->where('status', DriverProfileStatus::Approved->value)
            ->whereHas('user', fn (Builder $user) => $user
                ->where('account_status', AccountStatus::Active->value))
            ->where(fn (Builder $licence) => $licence
                ->whereNull('licence_expiry')
                ->orWhere('licence_expiry', '>=', now()->toDateString())));
    }

    /**
     * The bounding-box pre-filter: does this commute's rectangle contain the
     * passenger's origin and destination at all?
     *
     * Plain decimal comparisons on indexed columns — no spatial function, which
     * could not use an index and would read the table (pitfall #17). It is
     * deliberately generous: the box was built with a margin, and being slightly
     * too wide only means a few extra candidates for the next stage to measure
     * properly. Being too narrow would lose a match entirely.
     */
    private static function intersectBoundingBox(Builder $offer, SearchCriteria $criteria): void
    {
        foreach ([$criteria->origin, $criteria->destination] as $point) {
            $offer->where('bbox_min_lat', '<=', $point->lat)
                ->where('bbox_max_lat', '>=', $point->lat)
                ->where('bbox_min_lng', '<=', $point->lng)
                ->where('bbox_max_lng', '>=', $point->lng);
        }
    }

    /**
     * 🔴 Pitfall #27: a block is checked in BOTH directions. Looking only at who
     * the passenger blocked misses the case that matters more — the driver who
     * blocked them.
     *
     * A subquery rather than a join: the blocked set is small, and this keeps the
     * outer query's plan simple.
     */
    private static function excludeBlockedEitherWay(Builder $offer, User $passenger): void
    {
        $offer->whereNotExists(function ($query) use ($passenger): void {
            $query->selectRaw(1)
                ->from('blocked_users')
                ->where(function ($either) use ($passenger): void {
                    $either->where(function ($theyBlockedMe) use ($passenger): void {
                        $theyBlockedMe->whereColumn('blocked_users.blocker_user_id', 'commute_offers.driver_profile_id')
                            ->where('blocked_users.blocked_user_id', $passenger->id);
                    })->orWhere(function ($iBlockedThem) use ($passenger): void {
                        $iBlockedThem->where('blocked_users.blocker_user_id', $passenger->id)
                            ->whereColumn('blocked_users.blocked_user_id', 'commute_offers.driver_profile_id');
                    });
                });
        });
    }

    /**
     * 🔴 The passenger's `min rating` filter (prototype, Filters screen).
     *
     * A HARD filter, not a scoring penalty, per the Bible's own rule that "hard conflicts never
     * receive a soft score". Somebody who says she will not ride with anyone under four stars is
     * stating a condition; scoring it would put a 3.1-star driver in her results, ranked lower,
     * which is the same class of mistake as scoring a women-only breach instead of excluding it.
     *
     * 🔒 **A driver with no rating is kept, and this is the judgement in the method.** `null` means
     * "nobody has rated her yet", never zero — the project says so everywhere a rate is returned.
     * Excluding unrated drivers would hide every new driver from every filtered search, which is
     * both a cold start that starves supply and an untrue answer: nothing bad has been said about
     * her. The mobile guide tells the client to label the filter accordingly, because a passenger
     * who ticks "4+ stars" and is shown an unrated driver deserves to know why.
     *
     * @param  Builder<CommuteOffer>  $offer
     */
    private static function requireMinimumRating(Builder $offer, SearchCriteria $criteria): void
    {
        if ($criteria->minRating === null) {
            return;
        }

        /*
         * Written as "there is no evidence she is BELOW your bar" rather than "her rating is at or
         * above it", and the difference is the whole point. A `whereHas` on the stats row would
         * exclude a driver who has no row at all — which is every driver who has not yet completed
         * a trip, since the row is written when one does. The negative form keeps her: no row, or
         * no rating in it, both mean nothing has been said.
         *
         * A subquery rather than a three-hop relation, like the block check below: it compares
         * against `commute_offers.driver_profile_id`, which IS the user id (the profile is 1:1 and
         * keyed by it), and keeps the outer query's plan simple.
         */
        $offer->whereNotExists(function ($query) use ($criteria): void {
            $query->selectRaw(1)
                ->from('user_stats')
                ->whereColumn('user_stats.user_id', 'commute_offers.driver_profile_id')
                ->whereNotNull('user_stats.avg_rating_as_driver')
                ->where('user_stats.avg_rating_as_driver', '<', $criteria->minRating);
        });
    }

    /**
     * A driver may require a minimum number of completed verification levels.
     * Filtered rather than scored for the same reason as audience: the driver set
     * a condition for joining, not a preference about who ranks higher.
     */
    private static function requireTrustLevel(Builder $offer, User $passenger): void
    {
        $offer->where('min_trust_level', '<=', $passenger->trust_level);
    }
}
