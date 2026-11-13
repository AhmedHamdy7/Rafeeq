<?php

namespace App\Domains\Geo\Support;

use App\Domains\Shared\ValueObjects\Coordinate;

final readonly class RouteRemainder
{
    /**
     * @param  array<int, Coordinate>  $points
     */
    private function __construct(
        public float $offMetres,
        public float $remainingMetres,
        public float $totalMetres,
        public array $points,
    ) {}

    /**
     * @param  array<int, Coordinate>  $route
     */
    public static function from(array $route, Coordinate $car): self
    {
        if (count($route) < 2) {
            return new self(0.0, 0.0, 0.0, [$car, ...$route]);
        }

        $perLat = 111_320.0;
        $perLng = 111_320.0 * cos(deg2rad($car->lat));

        $px = $car->lng * $perLng;
        $py = $car->lat * $perLat;

        $shortest = null;
        $segment = 0;
        $before = 0.0;
        $travelled = 0.0;

        for ($i = 0; $i < count($route) - 1; $i++) {
            $ax = $route[$i]->lng * $perLng;
            $ay = $route[$i]->lat * $perLat;
            $dx = $route[$i + 1]->lng * $perLng - $ax;
            $dy = $route[$i + 1]->lat * $perLat - $ay;

            $squared = $dx ** 2 + $dy ** 2;
            $length = sqrt($squared);
            $t = $squared <= 0.0 ? 0.0 : max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $squared));
            $metres = sqrt(($px - $ax - $t * $dx) ** 2 + ($py - $ay - $t * $dy) ** 2);

            if ($shortest === null || $metres < $shortest) {
                $shortest = $metres;
                $segment = $i;
                $before = $travelled + $t * $length;
            }

            $travelled += $length;
        }

        return new self(
            offMetres: $shortest ?? 0.0,
            remainingMetres: max(0.0, $travelled - $before),
            totalMetres: $travelled,
            points: [$car, ...array_slice($route, $segment + 1)],
        );
    }
}
