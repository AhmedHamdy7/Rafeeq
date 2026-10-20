<?php

namespace App\Domains\Trip\Support;

use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Support\Polyline;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use App\Domains\Trip\Enums\NavigationStopKind;
use Carbon\CarbonImmutable;

/**
 * The run as the driver drives it: her start, every confirmed passenger's two points in the order
 * she reaches them, and her end (Feature 03 v5 §3).
 *
 * 🔴 **The ordering is the whole substance of this class, and v5 warns against the obvious wrong
 * answer in as many words:**
 *
 * > *"The backend supplies a validated `ordered_navigation_stops` list; **never assume the order is**
 * > `Start → A pickup → A drop-off → B pickup → B drop-off → End` in every case (shared journeys can
 * > interleave pickups and drop-offs)."*
 *
 * That naive order is not merely inelegant, it is a route nobody would drive. If A is collected in
 * Rehab and set down in Sheikh Zayed, and B lives one street from A, then seating A fully before
 * starting on B means driving the whole city and coming back for a neighbour. So the stops are
 * ordered by **how far along the driver's own route each point sits**, which is the question
 * `progressAlongRoute` was added to answer, and then one invariant is imposed on top: a
 * passenger's pickup always comes before her own drop-off. v5 §3 requires exactly that pair of
 * rules, no more.
 *
 * 🔴 **Nothing is deduplicated.** v5 counts "2 + 2N logical points ... before deduplication if
 * coordinates coincide", and its acceptance tests require the official route to hold exactly 4
 * points for one passenger and 6 for two. Two riders collected at the same corner are still two
 * people to wait for, two attendance decisions and two agreed meeting points — merging them would
 * make the second invisible.
 *
 * ⚠️ **It composes; it does not persist.** The route-preview endpoint builds an itinerary that
 * includes a PENDING request's points, and v5 forbids those from reaching the confirmed route. The
 * safest guarantee of that is a return value with no `save()` on it. Writing rows is
 * {@see BuildNavigationItineraryAction}'s job, and it is only ever handed
 * confirmed bookings.
 */
final readonly class NavigationItinerary
{
    /**
     * @param  list<ItineraryStop>  $stops  in driving order
     */
    private function __construct(
        public array $stops,
        public string $polyline,
        public Distance $distance,
        public int $durationSeconds,
    ) {}

    /**
     * @param  list<array{bookingId: string, kind: NavigationStopKind, point: Coordinate, label: string|null, reachedAt: CarbonImmutable|null}>  $passengerPoints
     *                                                                                                                                                             two per confirmed (or, for a preview, pending) booking
     * @param  CarbonImmutable|null  $departureAt  what the ETAs count from; null leaves every ETA null
     */
    public static function compose(
        GeoQueryEngine $geo,
        Coordinate $start,
        ?string $startLabel,
        Coordinate $end,
        ?string $endLabel,
        array $passengerPoints,
        ?CarbonImmutable $departureAt = null,
    ): self {
        $ordered = StopOrder::along($geo, $start, $end, $passengerPoints);

        /*
         * Every point the car visits, in order. The driver's endpoints bracket the list rather than
         * being members of `$passengerPoints`, because v5 §1 keeps them distinct: they are her
         * journey, not somebody's agreed meeting place.
         */
        $visits = [
            ['kind' => NavigationStopKind::DriverStart, 'point' => $start, 'label' => $startLabel, 'bookingId' => null, 'reachedAt' => null],
            ...$ordered,
            ['kind' => NavigationStopKind::DriverEnd, 'point' => $end, 'label' => $endLabel, 'bookingId' => null, 'reachedAt' => null],
        ];

        $stops = [];
        $linePoints = [];
        $totalMetres = 0;
        $totalSeconds = 0;
        $clock = $departureAt;

        foreach ($visits as $index => $visit) {
            $legSeconds = 0;
            $legMetres = 0;

            if ($index > 0) {
                /*
                 * One routing call per leg, between consecutive stops.
                 *
                 * 🔴 Legs rather than one call through every waypoint, because the ETAs are the
                 * point: a single call returns one total, and splitting that total across the stops
                 * by distance would be an invented number dressed as a measured one — which is what
                 * v5's "Do not create fake distance/duration/ETA values" forbids. The caching
                 * engine collapses legs that repeat between rebuilds, and the totals below are
                 * summed from the legs so the whole is always consistent with its parts.
                 */
                $leg = $geo->routeBetween($visits[$index - 1]['point'], $visit['point']);

                $legSeconds = $leg->durationSeconds;
                $legMetres = $leg->distance->metres;

                $totalSeconds += $legSeconds;
                $totalMetres += $legMetres;

                // The road itself, so the map draws the route rather than a set of straight hops.
                // The first point of each leg repeats the previous leg's last, so it is dropped.
                $legPoints = $leg->points();
                $linePoints = [...$linePoints, ...array_slice($legPoints, 1)];

                $clock = $clock?->addSeconds($legSeconds);
            } else {
                $legPoints = [$visit['point']];
                $linePoints = $legPoints;
            }

            $stops[] = new ItineraryStop(
                kind: $visit['kind'],
                point: $visit['point'],
                label: $visit['label'],
                sequence: $index,
                bookingId: $visit['bookingId'],
                legDurationSeconds: $legSeconds,
                legDistanceMeters: $legMetres,
                etaAt: $clock,
                reachedAt: $visit['reachedAt'],
            );
        }

        return new self(
            stops: $stops,
            polyline: Polyline::encode($linePoints),
            distance: Distance::fromMetres($totalMetres),
            durationSeconds: $totalSeconds,
        );
    }

    /**
     * How many stops a driver is being asked to drive to. v5's "2 + 2N logical points".
     */
    public function pointCount(): int
    {
        return count($this->stops);
    }

    /**
     * Every stop's coordinate in order, for a directions link.
     *
     * @return list<Coordinate>
     */
    public function coordinates(): array
    {
        return array_map(fn (ItineraryStop $stop): Coordinate => $stop->point, $this->stops);
    }
}
