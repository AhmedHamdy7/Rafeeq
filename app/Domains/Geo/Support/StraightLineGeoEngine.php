<?php

namespace App\Domains\Geo\Support;

use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;

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
}
