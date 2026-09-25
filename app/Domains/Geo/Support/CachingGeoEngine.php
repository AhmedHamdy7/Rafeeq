<?php

namespace App\Domains\Geo\Support;

use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Models\RouteCache;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;

/**
 * Wraps any engine and remembers the routes it produced, in `route_cache`.
 *
 * A routing provider is billed per call, and the same commute is looked up over
 * and over: the road from Rehab to Smart Village does not change between one
 * driver publishing and the next. Bible §15.3 is blunt that caching this is not
 * optional.
 *
 * Two details make the cache actually hit:
 *
 * Coordinates are rounded to four decimal places (~11m) before they become part
 * of the key. Two drivers pinning the same compound gate will not produce
 * identical floats, and an exact-match cache would miss every time.
 *
 * Distance and duration are cached for different lengths of time (pitfall #20).
 * The distance between two places is effectively permanent; how long it takes
 * is not. Giving them one lifetime means either paying repeatedly for a number
 * that never moves, or serving a travel time from last month.
 *
 * Only `routeBetween` is cached. The others are arithmetic on data the caller
 * already has, so a cache lookup would cost more than the answer.
 */
final readonly class CachingGeoEngine implements GeoQueryEngine
{
    public function __construct(private GeoQueryEngine $engine) {}

    public function distanceMeters(Coordinate $a, Coordinate $b): Distance
    {
        return $this->engine->distanceMeters($a, $b);
    }

    public function routeBetween(Coordinate $from, Coordinate $to, array $via = []): Route
    {
        $key = self::cacheKey($from, $to, $via);

        $cached = RouteCache::query()->whereKey($key)->first();

        if ($cached !== null && $cached->expires_at->isFuture()) {
            // Counted so cache efficiency is observable rather than assumed —
            // a cache nobody measures is a cache nobody knows is broken.
            $cached->increment('hit_count');

            return new Route(
                polyline: $cached->polyline,
                distance: Distance::fromMetres($cached->distance_meters),
                durationSeconds: $cached->duration_seconds,
            );
        }

        $route = $this->engine->routeBetween($from, $to, $via);

        $this->remember($key, $from, $to, $route);

        return $route;
    }

    public function detourMinutes(Route $original, Coordinate $pickup): float
    {
        return $this->engine->detourMinutes($original, $pickup);
    }

    public function overlapPercent(Route $a, Route $b): float
    {
        return $this->engine->overlapPercent($a, $b);
    }

    /**
     * @param  array<int, Coordinate>  $via
     */
    public static function cacheKey(Coordinate $from, Coordinate $to, array $via = []): string
    {
        $parts = array_map(
            fn (Coordinate $point) => $point->roundedTo(4)->lat.','.$point->roundedTo(4)->lng,
            [$from, ...$via, $to],
        );

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param  array<int, Coordinate>  $via
     */
    private function remember(string $key, Coordinate $from, Coordinate $to, Route $route): void
    {
        $days = (int) config('rafeeq.geo.route_cache_days');

        RouteCache::query()->updateOrCreate(
            ['cache_key' => $key],
            [
                'origin_lat' => $from->roundedTo(4)->lat,
                'origin_lng' => $from->roundedTo(4)->lng,
                'dest_lat' => $to->roundedTo(4)->lat,
                'dest_lng' => $to->roundedTo(4)->lng,
                'polyline' => $route->polyline,
                'distance_meters' => $route->distance->metres,
                'duration_seconds' => $route->durationSeconds,
                'provider' => config('rafeeq.geo.engine'),
                'fetched_at' => now(),
                'expires_at' => now()->addDays($days),
            ],
        );
    }
}
