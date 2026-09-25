<?php

namespace App\Domains\Matching\Support;

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\ValueObjects\WalkTime;

/**
 * The 100-point match model (Bible §Part 2, scene 4.3), in one place.
 *
 * | component   | weight | what it rewards                                |
 * |-------------|-------:|------------------------------------------------|
 * | overlap     |     30 | how much of the passenger's journey this covers |
 * | schedule    |     25 | how close the arrival is to what they asked for |
 * | detour      |     15 | how little the driver has to go out of the way  |
 * | audience    |     10 | the commute matches their audience preference   |
 * | comfort     |     10 | the house rules they asked for                  |
 * | price       |      5 | within their budget                             |
 * | reliability |      5 | the driver's record of turning up               |
 *
 * The weights say what Rafeeq is. Route overlap and schedule together are more
 * than half the score because a commute that is not going the passenger's way at
 * the time they need is not a match however pleasant it is; price is worth five
 * because this is shared cost, not a marketplace.
 *
 * What is NOT here matters as much. The audience component scores 10 or 0 for a
 * PREFERENCE — it never decides eligibility. A women-only commute excludes
 * non-women in the SQL query, before anything is scored (pitfall #15): scoring
 * that as "6 out of 10" would put a man in a women-only group's results, which
 * is a security failure, not a ranking one.
 */
final class ScoringModel
{
    private const int OVERLAP_WEIGHT = 30;

    private const int SCHEDULE_WEIGHT = 25;

    private const int DETOUR_WEIGHT = 15;

    private const int AUDIENCE_WEIGHT = 10;

    private const int COMFORT_WEIGHT = 10;

    private const int PRICE_WEIGHT = 5;

    private const int RELIABILITY_WEIGHT = 5;

    /**
     * @param  float  $overlapPercent  how much of the passenger's route this commute covers
     * @param  array<int, string>  $offerRules  house rules the commute actually has
     */
    public static function score(
        CommuteOffer $offer,
        ScheduledTrip $trip,
        SearchCriteria $criteria,
        float $overlapPercent,
        WalkTime $walk,
        float $detourMinutes,
        array $offerRules,
        ?float $driverOnTimeRate,
    ): ScoreBreakdown {
        return new ScoreBreakdown(
            overlap: self::overlap($overlapPercent),
            schedule: self::schedule($trip, $criteria),
            detour: self::detour($detourMinutes, $criteria->maxDetourMinutes),
            audience: self::audience($offer, $criteria),
            comfort: self::comfort($criteria->requiredRules, $offerRules),
            price: self::price($trip, $criteria),
            reliability: self::reliability($driverOnTimeRate),
            walkMinutes: $walk->minutes,
            detourMinutes: $detourMinutes,
        );
    }

    /**
     * Proportional to how much of the journey is shared. A commute covering 94%
     * of someone's route scores 28 of 30 — the figure in the Bible's worked
     * example.
     */
    private static function overlap(float $percent): int
    {
        return (int) round(min(100.0, max(0.0, $percent)) / 100 * self::OVERLAP_WEIGHT);
    }

    /**
     * Full marks for arriving inside the window the passenger asked for, then
     * falling away with each minute outside it.
     *
     * Measured against the LOCAL departure time, not the UTC instant: the
     * passenger said "around 8am" meaning their own clock, and across a DST
     * transition the same local time is a different instant.
     */
    private static function schedule(ScheduledTrip $trip, SearchCriteria $criteria): int
    {
        $departure = $trip->departure_local->format('H:i:s');

        if ($departure >= $criteria->arrivalWindowStart && $departure <= $criteria->arrivalWindowEnd) {
            return self::SCHEDULE_WEIGHT;
        }

        $minutesOut = $departure < $criteria->arrivalWindowStart
            ? self::minutesBetween($departure, $criteria->arrivalWindowStart)
            : self::minutesBetween($criteria->arrivalWindowEnd, $departure);

        // Thirty minutes out is worth nothing: a commuter who has to leave half
        // an hour early every day has not been matched, they have been
        // inconvenienced.
        $decay = max(0.0, 1 - $minutesOut / 30);

        return (int) round(self::SCHEDULE_WEIGHT * $decay);
    }

    /**
     * Full marks when the driver barely goes out of their way, falling to zero at
     * the limit the PASSENGER said they would accept.
     *
     * The passenger's tolerance rather than the driver's, because at this point
     * the driver's own limit has already been applied as a filter — anything
     * still here is a detour the driver agreed to, and what is left to rank is
     * how good it is for the person asking.
     */
    private static function detour(float $detourMinutes, int $maxDetourMinutes): int
    {
        if ($maxDetourMinutes <= 0) {
            return $detourMinutes <= 0.0 ? self::DETOUR_WEIGHT : 0;
        }

        $ratio = max(0.0, 1 - $detourMinutes / $maxDetourMinutes);

        return (int) round(self::DETOUR_WEIGHT * $ratio);
    }

    /**
     * A PREFERENCE, not eligibility. Someone who asked for women-only and found
     * it scores full marks; someone who did not ask still scores full marks,
     * because nothing about the commute disappoints them.
     *
     * Eligibility was settled in the query. By the time anything reaches here it
     * is already a commute this passenger is allowed to join.
     */
    private static function audience(CommuteOffer $offer, SearchCriteria $criteria): int
    {
        if ($criteria->audiencePreference === null) {
            return self::AUDIENCE_WEIGHT;
        }

        return $offer->audience === $criteria->audiencePreference ? self::AUDIENCE_WEIGHT : 0;
    }

    /**
     * How many of the house rules the passenger asked for this commute actually
     * has. Someone who asked for nothing is not disappointed, so they get full
     * marks rather than zero.
     *
     * @param  array<int, string>  $wanted
     * @param  array<int, string>  $offered
     */
    private static function comfort(array $wanted, array $offered): int
    {
        if ($wanted === []) {
            return self::COMFORT_WEIGHT;
        }

        $met = count(array_intersect($wanted, $offered));

        return (int) round($met / count($wanted) * self::COMFORT_WEIGHT);
    }

    /**
     * Within budget scores full; over it falls away. A passenger who named no
     * budget is not disappointed by any price.
     */
    private static function price(ScheduledTrip $trip, SearchCriteria $criteria): int
    {
        if ($criteria->budgetPerSeatPiastres === null || $criteria->budgetPerSeatPiastres <= 0) {
            return self::PRICE_WEIGHT;
        }

        if ($trip->price_snapshot_piastres <= $criteria->budgetPerSeatPiastres) {
            return self::PRICE_WEIGHT;
        }

        // Twice the budget is worth nothing. Between the two it tapers, so a
        // commute slightly over budget still ranks above one at double.
        $over = $trip->price_snapshot_piastres / $criteria->budgetPerSeatPiastres - 1;

        return (int) round(self::PRICE_WEIGHT * max(0.0, 1 - $over));
    }

    /**
     * The driver's record of turning up on time.
     *
     * A driver with no history scores full marks, not zero. Starting everyone at
     * the bottom would make the platform unusable for a new driver — they would
     * never be matched, so they would never build the history that would let them
     * be matched. Trust is earned by evidence of failure, not withheld for lack
     * of evidence of success.
     */
    private static function reliability(?float $onTimeRate): int
    {
        if ($onTimeRate === null) {
            return self::RELIABILITY_WEIGHT;
        }

        return (int) round(self::RELIABILITY_WEIGHT * min(1.0, max(0.0, $onTimeRate / 100)));
    }

    private static function minutesBetween(string $earlier, string $later): int
    {
        return (int) abs((strtotime("1970-01-01 {$later} UTC") - strtotime("1970-01-01 {$earlier} UTC")) / 60);
    }
}
