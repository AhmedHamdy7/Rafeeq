<?php

namespace App\Domains\Geo\Support;

use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Shared\ValueObjects\Coordinate;

final class NearestOnRoute
{
    public static function point(Route $route, Coordinate $to): ?Coordinate
    {
        $points = $route->points();

        if ($points === []) {
            return null;
        }

        if (count($points) === 1) {
            return $points[0];
        }

        $scale = cos(deg2rad($to->lat));
        $px = $to->lng * $scale;
        $py = $to->lat;

        $best = null;
        $bestSquared = null;

        for ($i = 0; $i < count($points) - 1; $i++) {
            $ax = $points[$i]->lng * $scale;
            $ay = $points[$i]->lat;
            $dx = $points[$i + 1]->lng * $scale - $ax;
            $dy = $points[$i + 1]->lat - $ay;

            $lengthSquared = $dx * $dx + $dy * $dy;
            $t = $lengthSquared === 0.0 ? 0.0 : max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $lengthSquared));

            $x = $ax + $t * $dx;
            $y = $ay + $t * $dy;
            $squared = ($px - $x) ** 2 + ($py - $y) ** 2;

            if ($bestSquared === null || $squared < $bestSquared) {
                $bestSquared = $squared;
                $best = new Coordinate($y, $scale == 0.0 ? $to->lng : $x / $scale);
            }
        }

        return $best;
    }
}
