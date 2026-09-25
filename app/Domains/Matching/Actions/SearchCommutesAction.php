<?php

namespace App\Domains\Matching\Actions;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Support\Haversine;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Models\MatchScore;
use App\Domains\Matching\Support\HardFilters;
use App\Domains\Matching\Support\MatchResult;
use App\Domains\Matching\Support\ScoreBreakdown;
use App\Domains\Matching\Support\ScoringModel;
use App\Domains\Matching\Support\SearchCriteria;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use App\Domains\Shared\ValueObjects\WalkTime;
use Illuminate\Database\Eloquent\Collection;

/**
 * The search engine (Chapter 5, Bible §7.2). The heart of the product.
 *
 * Four stages, narrowing hard before anything expensive runs:
 *
 *   1. SQL, no geography      ~50,000 → ~800   indexed integer comparisons
 *   2. walking distance       ~800    → ~40    arithmetic, in PHP
 *   3. detour                 ~40 rows         routing provider, cached
 *   4. score and rank         ~40 rows         the 100-point model
 *
 * The order is the design. Pitfall #16 is a loop that asks a routing provider
 * about every candidate: at roughly four dollars a search, five searches by one
 * person is twenty dollars. Stage one is what makes stage three affordable, and
 * stage one contains no geography at all — just integers and booleans on indexed
 * columns.
 *
 * Rafeeq never dispatches. This finds commutes a driver already published and
 * ranks them for the person asking; nobody is assigned to anybody.
 */
final readonly class SearchCommutesAction
{
    /**
     * How many candidates survive into the expensive stages. Bounded because the
     * cost of stage three is linear in this number, and a passenger reading a
     * list has no use for the hundredth result.
     */
    private const int SCORING_LIMIT = 60;

    private const int RESULT_LIMIT = 20;

    public function __construct(private GeoQueryEngine $geo) {}

    /**
     * @return array<int, MatchResult>
     */
    public function execute(User $passenger, SearchCriteria $criteria): array
    {
        $candidates = $this->stageOneHardFilters($passenger, $criteria);

        if ($candidates->isEmpty()) {
            return [];
        }

        $withinWalk = $this->stageTwoWalkingDistance($candidates, $criteria);

        if ($withinWalk === []) {
            return [];
        }

        $scored = $this->stageThreeAndFour($withinWalk, $criteria, $passenger);

        $this->cache($scored, $criteria->signature($passenger->id));

        return $this->groupByCommute($scored);
    }

    /**
     * Stage 1 — everything the passenger may not see, gone in SQL.
     *
     * @return Collection<int, ScheduledTrip>
     */
    private function stageOneHardFilters(User $passenger, SearchCriteria $criteria)
    {
        return HardFilters::apply(ScheduledTrip::query(), $passenger, $criteria)
            // Eager-loaded because the next stages read all of it, and
            // `preventLazyLoading` would otherwise turn this into an exception —
            // which is exactly the N+1 it exists to catch.
            ->with([
                'commuteOffer.locations',
                'commuteOffer.rules',
                'commuteOffer.vehicle',
                // The driver's USER too: the result card shows their public first
                // name and trust level. Without it, rendering a page of results
                // would be one query per driver — which is what
                // `preventLazyLoading` exists to turn into a failure rather than
                // a slow page nobody notices.
                'commuteOffer.driverProfile.user',
                'commuteSchedule',
            ])
            ->orderBy('departure_at')
            ->limit(self::SCORING_LIMIT * 4)
            ->get();
    }

    /**
     * Stage 2 — is there a meeting point the passenger can actually walk to?
     *
     * Arithmetic in PHP rather than `ST_Distance_Sphere` in the query: the rows
     * are already in memory after stage one, and a database round trip to compare
     * numbers we are holding would cost more than the comparison.
     *
     * @param  Collection<int, ScheduledTrip>  $candidates
     * @return array<int, array{trip: ScheduledTrip, meetingPoint: CommuteLocation, walk: WalkTime}>
     */
    private function stageTwoWalkingDistance($candidates, SearchCriteria $criteria): array
    {
        $survivors = [];

        foreach ($candidates as $trip) {
            $nearest = $this->nearestMeetingPoint($trip->commuteOffer, $criteria->origin);

            if ($nearest === null) {
                continue;
            }

            [$point, $distance] = $nearest;

            $walk = $distance->asWalkTime();

            // The passenger's own limit. A commute they would have to walk
            // twenty minutes to is not a match for someone who said fifteen.
            if (! $walk->isWithin($criteria->maxWalk)) {
                continue;
            }

            $survivors[] = ['trip' => $trip, 'meetingPoint' => $point, 'walk' => $walk];
        }

        return $survivors;
    }

    /**
     * The closest point on this commute the passenger could be picked up from —
     * its origin or any of its pickup points.
     *
     * @return array{0: CommuteLocation, 1: Distance}|null
     */
    private function nearestMeetingPoint(CommuteOffer $offer, Coordinate $from): ?array
    {
        $best = null;
        $bestDistance = null;

        foreach ($offer->locations as $location) {
            if ($location->type !== CommuteLocationType::Origin
                && $location->type !== CommuteLocationType::Pickup) {
                continue;
            }

            $distance = Haversine::between(
                $from,
                new Coordinate((float) $location->lat, (float) $location->lng),
            );

            if ($bestDistance === null || $distance->metres < $bestDistance->metres) {
                $best = $location;
                $bestDistance = $distance;
            }
        }

        return $best === null ? null : [$best, $bestDistance];
    }

    /**
     * Stages 3 and 4 — the detour a driver would take, then the score.
     *
     * The passenger's own route is computed ONCE, outside the loop, and each
     * commute's stored polyline is reused rather than re-requested: the route was
     * paid for when the commute was published. What remains inside the loop is
     * arithmetic on geometry already in memory.
     *
     * @param  array<int, array{trip: ScheduledTrip, meetingPoint: CommuteLocation, walk: WalkTime}>  $candidates
     * @return array<int, MatchResult>
     */
    private function stageThreeAndFour(array $candidates, SearchCriteria $criteria, User $passenger): array
    {
        $passengerRoute = $this->geo->routeBetween($criteria->origin, $criteria->destination);

        $results = [];

        foreach (array_slice($candidates, 0, self::SCORING_LIMIT) as $candidate) {
            $trip = $candidate['trip'];
            $offer = $trip->commuteOffer;

            $offerRoute = $this->routeOf($offer);

            if ($offerRoute === null) {
                continue;
            }

            $detour = $this->geo->detourMinutes($offerRoute, $criteria->origin);

            // The driver's own tolerance, applied as a filter: a detour they never
            // agreed to is not something to rank lower, it is not on offer.
            if ($detour > $offer->max_detour_minutes) {
                continue;
            }

            $score = ScoringModel::score(
                offer: $offer,
                trip: $trip,
                criteria: $criteria,
                overlapPercent: $this->geo->overlapPercent($offerRoute, $passengerRoute),
                walk: $candidate['walk'],
                detourMinutes: $detour,
                offerRules: $this->rulesOf($offer),
                driverOnTimeRate: $offer->driverProfile->on_time_rate === null
                    ? null
                    : (float) $offer->driverProfile->on_time_rate,
            );

            $results[] = new MatchResult($offer, $trip, $score, $candidate['meetingPoint']);
        }

        usort($results, fn (MatchResult $a, MatchResult $b) => $b->score->total() <=> $a->score->total());

        return $results;
    }

    /**
     * The commute's published route, rebuilt from the polyline stored at publish
     * time. Nothing is re-requested from a provider: the road has not changed, and
     * the whole point of computing it once was not to pay for it again.
     */
    private function routeOf(CommuteOffer $offer): ?Route
    {
        if ($offer->route_polyline === null) {
            return null;
        }

        return new Route(
            polyline: $offer->route_polyline,
            distance: Distance::fromMetres((int) $offer->route_distance_meters),
            durationSeconds: (int) $offer->route_duration_seconds,
        );
    }

    /**
     * @return array<int, string>
     */
    private function rulesOf(CommuteOffer $offer): array
    {
        $keys = [];

        foreach ($offer->rules as $rule) {
            if ($rule->rule_value) {
                $keys[] = $rule->rule_key->value;
            }
        }

        return $keys;
    }

    /**
     * Stage 5 — remember the answer for an hour (Bible §7.3).
     *
     * A passenger refining filters re-searches repeatedly, and the same question
     * should not re-run four stages. An hour because seats move: longer and the
     * cache would be offering days that filled up.
     *
     * @param  array<int, MatchResult>  $results
     */
    private function cache(array $results, string $signature): void
    {
        foreach ($results as $result) {
            MatchScore::query()->updateOrCreate(
                [
                    'demand_signature' => $signature,
                    'scheduled_trip_id' => $result->trip->id,
                ],
                [
                    ...$result->score->toColumns(),
                    'expires_at' => now()->addHour(),
                ],
            );
        }
    }

    /**
     * One card per commute, not one per day.
     *
     * A recurring search over a month would otherwise return the same commute
     * twenty times, pushing every other driver off the first screen — the
     * passenger asked for a commute, and the days are what they pick once they
     * have chosen one.
     *
     * @param  array<int, MatchResult>  $results
     * @return array<int, MatchResult>
     */
    private function groupByCommute(array $results): array
    {
        $byOffer = [];

        foreach ($results as $result) {
            $offerId = $result->offer->id;

            if (! array_key_exists($offerId, $byOffer)) {
                // The first one wins: results are already sorted by score, and
                // within one commute the earliest day comes first.
                $byOffer[$offerId] = ['best' => $result, 'others' => []];

                continue;
            }

            $byOffer[$offerId]['others'][] = $result->trip;
        }

        $cards = [];

        foreach ($byOffer as $entry) {
            $cards[] = $entry['best']->withOtherDays($entry['others']);
        }

        return array_slice($cards, 0, self::RESULT_LIMIT);
    }

    /**
     * A cached answer for this exact search, if one is still warm.
     *
     * Read back as scores rather than as results: seats may have moved since, so
     * the trips are re-fetched and re-filtered. Chapter 5's edge case — "commute
     * becomes full after search" — is why a cache hit is not simply replayed.
     *
     * @return array<int, ScoreBreakdown>
     */
    public static function cachedScores(string $signature): array
    {
        $scores = [];

        $rows = MatchScore::query()
            ->where('demand_signature', $signature)
            ->where('expires_at', '>', now())
            ->get();

        foreach ($rows as $row) {
            $scores[$row->scheduled_trip_id] = new ScoreBreakdown(
                overlap: $row->overlap_score,
                schedule: $row->schedule_score,
                detour: $row->detour_score,
                audience: $row->audience_score,
                comfort: $row->comfort_score,
                price: $row->price_score,
                reliability: $row->reliability_score,
                walkMinutes: (float) $row->walk_minutes,
                detourMinutes: (float) $row->detour_minutes,
            );
        }

        return $scores;
    }
}
