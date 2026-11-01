<?php

namespace App\Domains\Geo\Support;

use App\Domains\Shared\ValueObjects\Coordinate;

/**
 * A route with its two ends cut off — what a passenger sees of a commute before she holds a seat
 * on it (DISCOVER-1, Q2 option D, SAFE-18).
 *
 * 🔒 The ends are the point. A commute starts at somebody's front door and ends at their work, and
 * a line drawn to the metre on a map is both addresses. About a kilometre off each end shows which
 * way it runs and which neighbourhoods it passes, and leaves the two places that identify a person
 * out.
 *
 * Cut by DISTANCE along the line, with a point interpolated exactly at the cut: a provider's
 * polyline has a vertex every few hundred metres, and snapping to the nearest vertex would trim
 * anywhere between nothing and much more than asked — on a straight road, nothing at all.
 */
final class TrimmedRoute
{
    /** How much to cut from each end. */
    public const int TRIM_METRES = 1000;

    /**
     * @return array<int, Coordinate> the points left, first and last being the new ends; empty
     *                                when the route cannot be read
     */
    public static function of(string $encoded, int $trimMetres = self::TRIM_METRES): array
    {
        if (! Polyline::isReadable($encoded)) {
            return [];
        }

        $points = Polyline::decode($encoded);
        $total = Haversine::alongPath($points)->metres;

        /*
         * A route too short to lose two kilometres keeps its middle third instead. Cutting it to
         * nothing would draw no line at all, and keeping it whole would hand over both ends of a
         * short commute — which is the case the trim exists for.
         */
        [$from, $to] = $total > 3 * $trimMetres
            ? [$trimMetres, $total - $trimMetres]
            : [$total / 3, 2 * $total / 3];

        return self::between($points, $from, $to);
    }

    /**
     * The stretch of a path between two distances along it.
     *
     * @param  array<int, Coordinate>  $points
     * @return array<int, Coordinate>
     */
    private static function between(array $points, float $from, float $to): array
    {
        $kept = [];
        $travelled = 0.0;

        for ($i = 1, $count = count($points); $i < $count; $i++) {
            $a = $points[$i - 1];
            $b = $points[$i];
            $leg = (float) Haversine::between($a, $b)->metres;
            $legEnd = $travelled + $leg;

            if ($kept === [] && $legEnd >= $from) {
                $kept[] = self::along($a, $b, $leg === 0.0 ? 0.0 : ($from - $travelled) / $leg);
            }

            if ($kept !== [] && $legEnd >= $to) {
                $kept[] = self::along($a, $b, $leg === 0.0 ? 0.0 : ($to - $travelled) / $leg);

                return $kept;
            }

            if ($kept !== []) {
                $kept[] = $b;
            }

            $travelled = $legEnd;
        }

        return $kept;
    }

    /**
     * A point a fraction of the way from one point to the next. Linear in degrees, which over a
     * few hundred metres is indistinguishable from the great circle.
     */
    private static function along(Coordinate $a, Coordinate $b, float $fraction): Coordinate
    {
        $fraction = max(0.0, min(1.0, $fraction));

        return new Coordinate(
            $a->lat + ($b->lat - $a->lat) * $fraction,
            $a->lng + ($b->lng - $a->lng) * $fraction,
        );
    }
}
