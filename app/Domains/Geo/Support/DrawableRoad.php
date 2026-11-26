<?php

namespace App\Domains\Geo\Support;

use App\Domains\Geo\ValueObjects\Route;
use Carbon\CarbonInterface;

final class DrawableRoad
{
    public static function stored(?string $polyline, ?CarbonInterface $fetchedAt): ?string
    {
        if ($polyline === null || $fetchedAt === null || ! Polyline::isReadable($polyline)) {
            return null;
        }

        if ($fetchedAt->lessThan(now()->subDays(self::keepDays()))) {
            return null;
        }

        return $polyline;
    }

    public static function of(?Route $route): ?string
    {
        if ($route === null || $route->isFallback || ! Polyline::isReadable($route->polyline)) {
            return null;
        }

        return $route->polyline;
    }

    public static function keepDays(): int
    {
        return max(1, (int) config('rafeeq.geo.road_keep_days'));
    }

    public static function refreshDays(): int
    {
        return max(1, min(self::keepDays(), (int) config('rafeeq.geo.road_refresh_days')));
    }
}
