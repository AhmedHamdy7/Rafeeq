<?php

namespace App\Domains\Geo\Support;

use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;

/**
 * Great-circle distance between two points on the earth.
 *
 * In PHP rather than as `ST_Distance_Sphere` because most callers already hold
 * both coordinates in memory and do not need a database round trip for
 * arithmetic. The SQL function stays for set-based queries inside the Geo
 * domain, where the work has to happen next to the rows.
 *
 * Accurate to well under a percent at city scale, which is far finer than any
 * decision it feeds: nothing here distinguishes 1,200 from 1,206 metres.
 */
final class Haversine
{
    /**
     * Mean earth radius. The same figure MySQL's `ST_Distance_Sphere` uses by
     * default, so the two agree.
     */
    private const float EARTH_RADIUS_METRES = 6_371_009.0;

    public static function between(Coordinate $a, Coordinate $b): Distance
    {
        $latFrom = deg2rad($a->lat);
        $latTo = deg2rad($b->lat);
        $latDelta = $latTo - $latFrom;
        $lngDelta = deg2rad($b->lng - $a->lng);

        $h = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;

        // `asin(sqrt())` rather than `atan2`: numerically steadier for the
        // short distances this system deals in.
        return Distance::fromMetres(2 * self::EARTH_RADIUS_METRES * asin(min(1.0, sqrt($h))));
    }

    /**
     * Total length along a sequence of points.
     *
     * @param  array<int, Coordinate>  $points
     */
    public static function alongPath(array $points): Distance
    {
        $metres = 0;

        for ($i = 1, $count = count($points); $i < $count; $i++) {
            $metres += self::between($points[$i - 1], $points[$i])->metres;
        }

        return Distance::fromMetres($metres);
    }
}
