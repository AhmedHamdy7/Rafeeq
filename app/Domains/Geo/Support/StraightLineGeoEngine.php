<?php

namespace App\Domains\Geo\Support;

use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use Carbon\CarbonImmutable;

/**
 * A routing engine with no provider behind it: every route is straight lines
 * between the given points.
 *
 * This is the development and test engine, and it is honest about what it is.
 * It does not pretend to know roads, so the distances it returns are shorter
 * than reality and its durations are an assumed average speed.
 *
 * Unlike the OTP sender and the virus scanner, this one does NOT refuse to run
 * in production. A wrong travel-time estimate is a degraded product; an
 * unscanned upload or an undelivered login code is a broken one. Refusing here
 * would mean a provider outage takes publishing down entirely, which is worse
 * than a rougher estimate — the caller can see which provider answered.
 */
final class StraightLineGeoEngine implements GeoQueryEngine
{
    /**
     * Average city speed used to turn a distance into a duration. Deliberately
     * pessimistic for Cairo traffic; it is an estimate either way, and one that
     * flatters the journey is worse than one that does not.
     */
    private const float ASSUMED_KMH = 28.0;

    public function distanceMeters(Coordinate $a, Coordinate $b): Distance
    {
        return Haversine::between($a, $b);
    }

    public function routeBetween(Coordinate $from, Coordinate $to, array $via = []): Route
    {
        $points = [$from, ...$via, $to];

        $distance = Haversine::alongPath($points);

        return new Route(
            polyline: Polyline::encode($points),
            distance: $distance,
            durationSeconds: (int) round($distance->kilometres() / self::ASSUMED_KMH * 3600),
            points: $points,
        );
    }

    /**
     * No traffic to know about, so the clock changes nothing: the same straight lines at the same
     * assumed speed. Honest rather than clever — a departure computed from this is the same
     * estimate the rest of this engine gives, and the caller can see which engine answered.
     */
    public function routeArrivingBy(Coordinate $from, Coordinate $to, array $via, CarbonImmutable $arriveBy): Route
    {
        return $this->routeBetween($from, $to, $via);
    }

    public function detourMinutes(Route $original, Coordinate $pickup): float
    {
        $points = $original->points();

        if (count($points) < 2) {
            return 0.0;
        }

        $from = $points[0];
        $to = $points[count($points) - 1];

        $withPickup = Haversine::between($from, $pickup)->metres
            + Haversine::between($pickup, $to)->metres;

        $extraMetres = max(0, $withPickup - $original->distance->metres);

        return round($extraMetres / 1000 / self::ASSUMED_KMH * 60, 2);
    }

    /**
     * How much of `$b` runs near `$a`, measured by sampling `$b`'s points and
     * asking how many lie close to any point of `$a`.
     *
     * Crude on purpose. The real engine will compare geometries properly; what
     * matters here is that the shape of the answer is right — 0 for unrelated
     * routes, 100 for identical ones — so everything built on top can be tested
     * before a provider exists.
     */
    public function overlapPercent(Route $a, Route $b): float
    {
        $aPoints = $a->points();
        $bPoints = $b->points();

        if ($aPoints === [] || $bPoints === []) {
            return 0.0;
        }

        // Generous, because a straight line between two points is a poor stand-in
        // for the road: a real route wanders away from it by more than a few
        // hundred metres even when it is the same journey.
        $tolerance = Distance::fromKilometres(2);

        $near = 0;

        foreach ($bPoints as $point) {
            foreach ($aPoints as $candidate) {
                if (Haversine::between($point, $candidate)->isWithin($tolerance)) {
                    $near++;

                    break;
                }
            }
        }

        return round($near / count($bPoints) * 100, 2);
    }

    /**
     * Shortest distance from the point to the route's line, segment by segment.
     *
     * 🔴 To the SEGMENTS, not to the vertices. A polyline from a provider has a vertex every few
     * hundred metres on a straight stretch, so measuring to the nearest vertex would report a car
     * driving exactly down the middle of the road as hundreds of metres off it. That is the
     * difference between a deviation alert somebody acts on and one they learn to ignore.
     *
     * Each segment is solved on a local flat plane rather than on the sphere. Over the few hundred
     * metres of one segment the curvature is far below the error in a phone's own fix, and the flat
     * version is a handful of multiplications where the spherical one is several trigonometric
     * calls — which matters when this runs on every position report from every live run.
     */
    public function distanceFromRoute(Route $route, Coordinate $point): Distance
    {
        $points = $route->points();

        if ($points === []) {
            // No route to be off. Zero rather than a huge number, because "we do not know" must
            // not read as "she has driven into the desert".
            return Distance::fromMetres(0);
        }

        if (count($points) === 1) {
            return Haversine::between($points[0], $point);
        }

        return Distance::fromMetres((int) round(self::nearestApproach($points, $point)['metres']));
    }

    /**
     * The fraction of the route's length at the point's nearest approach to it.
     *
     * Built on the same segment projection as `distanceFromRoute`, and for the same reason: the
     * closest place on the LINE, not the closest vertex. Snapping to vertices would give every
     * stop on a straight stretch the same position and flatten the ordering this produces.
     */
    public function progressAlongRoute(Route $route, Coordinate $point): float
    {
        $points = $route->points();

        // Nothing to be along. Zero rather than null, so a caller ordering stops gets a usable
        // number and an unroutable commute is refused where that is actually decided.
        if (count($points) < 2) {
            return 0.0;
        }

        $nearest = self::nearestApproach($points, $point);

        if ($nearest['totalLength'] <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $nearest['lengthBefore'] / $nearest['totalLength']));
    }

    /**
     * Walks the line once and reports where the point comes closest to it.
     *
     * One pass for both questions, with one cosine for the whole calculation rather than one per
     * segment. Each segment is solved on a local flat plane: over the few hundred metres of one
     * segment the curvature is far below the error in a phone's own fix, and the flat version is a
     * handful of multiplications where the spherical one is several trigonometric calls — which
     * matters when this runs on every position report from every live run.
     *
     * @param  array<int, Coordinate>  $points
     * @return array{metres: float, lengthBefore: float, totalLength: float}
     */
    private static function nearestApproach(array $points, Coordinate $point): array
    {
        $metresPerDegreeLat = 111_320.0;
        $metresPerDegreeLng = 111_320.0 * cos(deg2rad($point->lat));

        $px = $point->lng * $metresPerDegreeLng;
        $py = $point->lat * $metresPerDegreeLat;

        $shortest = null;
        $lengthBefore = 0.0;
        $travelled = 0.0;

        for ($i = 0; $i < count($points) - 1; $i++) {
            $ax = $points[$i]->lng * $metresPerDegreeLng;
            $ay = $points[$i]->lat * $metresPerDegreeLat;
            $bx = $points[$i + 1]->lng * $metresPerDegreeLng;
            $by = $points[$i + 1]->lat * $metresPerDegreeLat;

            [$metres, $t] = self::closestOnSegment($px, $py, $ax, $ay, $bx, $by);

            $segmentLength = sqrt(($bx - $ax) ** 2 + ($by - $ay) ** 2);

            if ($shortest === null || $metres < $shortest) {
                $shortest = $metres;
                // How far along the whole line the closest place is: everything before this
                // segment, plus the part of this segment the projection fell on.
                $lengthBefore = $travelled + $t * $segmentLength;
            }

            $travelled += $segmentLength;
        }

        return [
            'metres' => $shortest ?? 0.0,
            'lengthBefore' => $lengthBefore,
            'totalLength' => $travelled,
        ];
    }

    /**
     * Point-to-segment distance on a plane, and where along the segment the closest place is.
     *
     * The projection is clamped to the segment, which is the whole point: an unclamped projection
     * measures to the infinite LINE through the two vertices, so a car sitting beside the start of
     * a route would be reported as on it.
     *
     * @return array{0: float, 1: float} distance in metres, and the position 0–1 along the segment
     */
    private static function closestOnSegment(
        float $px,
        float $py,
        float $ax,
        float $ay,
        float $bx,
        float $by,
    ): array {
        $dx = $bx - $ax;
        $dy = $by - $ay;

        $lengthSquared = $dx * $dx + $dy * $dy;

        if ($lengthSquared === 0.0) {
            // A zero-length segment: two identical vertices, which real polylines do contain.
            return [sqrt(($px - $ax) ** 2 + ($py - $ay) ** 2), 0.0];
        }

        $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $lengthSquared;
        $t = max(0.0, min(1.0, $t));

        $closestX = $ax + $t * $dx;
        $closestY = $ay + $t * $dy;

        return [sqrt(($px - $closestX) ** 2 + ($py - $closestY) ** 2), $t];
    }
}
