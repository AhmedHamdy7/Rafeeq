<?php

namespace App\Domains\Geo\Support;

use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Models\RouteCache;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use Carbon\CarbonImmutable;

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
 * Only `routeBetween` and `routeArrivingBy` are cached. The others are arithmetic on data the
 * caller already has, so a cache lookup would cost more than the answer.
 *
 * 🔴 A route the engine marks `isFallback` is never written. That is the straight-line estimate
 * standing in for a provider that failed, and caching it for `route_cache_days` would turn one bad
 * minute at Google into a month of guessed journeys.
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

        $this->remember($key, $from, $to, $route, now()->addDays((int) config('rafeeq.geo.route_cache_days')));

        return $route;
    }

    /**
     * Cached by the LOCAL weekday and a slice of the local clock, not by the date (C-2).
     *
     * Cairo's traffic repeats by the week: 08:45 on one Sunday is the same road as 08:45 on the
     * next, so the same commute's departure is computed once a week rather than once per morning
     * across the thirty-day horizon. The slice (`traffic_bucket_minutes`) keeps two arrival times
     * a few minutes apart on one entry.
     *
     * 🔴 Read in the commute's local zone, never UTC. Egypt observes DST: 09:00 local is 06:00 UTC
     * in summer and 07:00 UTC in winter, and a UTC bucket would hand July's traffic to December.
     *
     * The lifetime is hours, not days (pitfall #20): this is a DURATION, and durations go stale.
     */
    public function routeArrivingBy(Coordinate $from, Coordinate $to, array $via, CarbonImmutable $arriveBy): Route
    {
        $key = self::trafficCacheKey($from, $to, $via, $arriveBy);

        $cached = RouteCache::query()->whereKey($key)->first();

        if ($cached !== null && $cached->expires_at->isFuture()) {
            $cached->increment('hit_count');

            return new Route(
                polyline: $cached->polyline,
                distance: Distance::fromMetres($cached->distance_meters),
                durationSeconds: $cached->duration_seconds,
            );
        }

        $route = $this->engine->routeArrivingBy($from, $to, $via, $arriveBy);

        $this->remember($key, $from, $to, $route, now()->addHours((int) config('rafeeq.geo.traffic_cache_hours')));

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
     * Passed straight through, and deliberately not cached: it is pure arithmetic on data already
     * in memory, so there is no provider call to save. Caching it would spend a cache round trip
     * to avoid a few dozen multiplications, and the key — a route plus a position that changes
     * every five seconds — would never be hit twice.
     */
    public function distanceFromRoute(Route $route, Coordinate $point): Distance
    {
        return $this->engine->distanceFromRoute($route, $point);
    }

    /**
     * Passed through uncached, for the same reason as the method above: it walks a line that is
     * already in memory and calls no provider, so a cache lookup would cost more than the answer.
     */
    public function progressAlongRoute(Route $route, Coordinate $point): float
    {
        return $this->engine->progressAlongRoute($route, $point);
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
     * The key for a timed route: the plain route's key, plus the local weekday and clock slice.
     *
     * @param  array<int, Coordinate>  $via
     */
    public static function trafficCacheKey(Coordinate $from, Coordinate $to, array $via, CarbonImmutable $arriveBy): string
    {
        $bucket = max(1, (int) config('rafeeq.geo.traffic_bucket_minutes'));

        $local = $arriveBy->setTimezone((string) config('rafeeq.geo.traffic_timezone'));

        $minuteOfDay = intdiv($local->hour * 60 + $local->minute, $bucket) * $bucket;

        return hash('sha256', self::cacheKey($from, $to, $via).'|arrive:'.$local->dayOfWeek.':'.$minuteOfDay);
    }

    private function remember(string $key, Coordinate $from, Coordinate $to, Route $route, mixed $expiresAt): void
    {
        if ($route->isFallback) {
            return;
        }

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
                'expires_at' => $expiresAt,
            ],
        );
    }
}
