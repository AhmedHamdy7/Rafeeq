<?php

namespace App\Domains\Geo\Support;

use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real roads and real traffic, from the Google Routes API v2 (`computeRoutes`).
 *
 * Selected with `RAFEEQ_GEO_ENGINE=google` and a key in `GOOGLE_MAPS_API_KEY`. Always wrapped by
 * {@see CachingGeoEngine}, because Google bills per call and the same commute is asked about over
 * and over (pitfall #16 is a search that pays once per candidate).
 *
 * What it answers from Google, and what it does not:
 *
 * - **`routeBetween`** — the road route, distance and duration, with the passengers' stops as
 *   `intermediates` in the order given. The distance is the real road distance, which is what the
 *   automatic seat price is computed from (PAY-42) — so a straight line here would under-price
 *   every seat.
 * - **`routeArrivingBy`** — the same route with a TRAFFIC-AWARE duration for the morning in
 *   question (C-2). Google times a drive from a DEPARTURE, not to an arrival, so this asks twice:
 *   once with the departure the plain duration implies, then once more from the departure that
 *   traffic implies. Two calls converge to within a minute or two on a commute, which is finer
 *   than anybody reads a "leave at" time.
 * - **Everything else** is geometry on a line already in memory — how far a point is from it, how
 *   far along it, how much two lines overlap — and is answered locally by the straight-line engine's
 *   arithmetic. Paying Google to measure a polyline we already hold would be pitfall #16 again.
 *
 * 🔴 **It never breaks publishing.** Any failure — no key, a timeout, a 4xx/5xx, a body with no
 * route in it — falls back to the straight-line estimate, logs a warning, and marks the route
 * `isFallback` so the cache does not keep it. A provider outage costs accuracy for a few minutes; it
 * must not cost a driver the ability to publish, or a passenger the ability to book.
 */
final class GoogleRoutesGeoEngine implements GeoQueryEngine
{
    /**
     * Only these come back from Google. The field mask is required by the API, and it is also the
     * bill: Routes prices by the fields requested.
     */
    private const string FIELD_MASK = 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline';

    /**
     * The most points either line is reduced to before the overlap comparison. That comparison is
     * every point against every point; on two Google polylines of several hundred vertices each it
     * would run hundreds of thousands of distance calculations per search candidate. Fifty keeps the
     * shape and costs nothing anybody would notice.
     */
    private const int OVERLAP_SAMPLE_POINTS = 50;

    /**
     * Below this, a second traffic call would move the departure by less than anybody reads.
     */
    private const int REFINE_THRESHOLD_SECONDS = 60;

    private StraightLineGeoEngine $geometry;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $endpoint,
        private readonly int $timeoutSeconds,
    ) {
        $this->geometry = new StraightLineGeoEngine;
    }

    public function distanceMeters(Coordinate $a, Coordinate $b): Distance
    {
        // As the crow flies is the question here, and it never needs a provider.
        return $this->geometry->distanceMeters($a, $b);
    }

    public function routeBetween(Coordinate $from, Coordinate $to, array $via = []): Route
    {
        return $this->compute($from, $to, $via, null)
            ?? $this->fallback($from, $to, $via);
    }

    public function routeArrivingBy(Coordinate $from, Coordinate $to, array $via, CarbonImmutable $arriveBy): Route
    {
        $plain = $this->routeBetween($from, $to, $via);

        if ($plain->isFallback) {
            // Google is not answering; asking it twice more would only add two timeouts.
            return $plain;
        }

        $departAt = $arriveBy->subSeconds($plain->durationSeconds);

        /*
         * Google refuses a traffic-aware request for a departure in the past. A morning whose
         * departure has already gone by needs no traffic answer anyway: nobody is told when to leave
         * for a journey that should already have started.
         */
        if (! $departAt->isFuture()) {
            return $plain;
        }

        $traffic = $this->compute($from, $to, $via, $departAt);

        if ($traffic === null) {
            return $plain;
        }

        $refinedDepartAt = $arriveBy->subSeconds($traffic->durationSeconds);

        if (abs($refinedDepartAt->diffInSeconds($departAt)) > self::REFINE_THRESHOLD_SECONDS
            && $refinedDepartAt->isFuture()) {
            $traffic = $this->compute($from, $to, $via, $refinedDepartAt) ?? $traffic;
        }

        return $traffic;
    }

    /**
     * The extra time this one stop adds — estimated locally, never asked of Google.
     *
     * 🔴 Search calls this once per candidate commute, inside a loop (pitfall #16): a provider call
     * here would bill one request per commute per search. So the extra distance is measured as the
     * crow flies (out to the pickup and on to the end, minus the straight run), and turned into
     * minutes at the route's OWN average speed — Google's road duration over Google's road
     * distance — which is far closer to Cairo traffic than a fixed assumed speed.
     *
     * The whole-trip detour cap (COM-31) does not rely on this: it asks the real road for the route
     * through every rider's points, once per change.
     */
    public function detourMinutes(Route $original, Coordinate $pickup): float
    {
        $points = $original->points();

        if (count($points) < 2) {
            return 0.0;
        }

        $from = $points[0];
        $to = $points[count($points) - 1];

        $extraMetres = max(0,
            Haversine::between($from, $pickup)->metres
            + Haversine::between($pickup, $to)->metres
            - Haversine::between($from, $to)->metres,
        );

        if ($original->distance->metres <= 0 || $original->durationSeconds <= 0) {
            return $this->geometry->detourMinutes($original, $pickup);
        }

        $secondsPerMetre = $original->durationSeconds / $original->distance->metres;

        return round($extraMetres * $secondsPerMetre / 60, 2);
    }

    public function overlapPercent(Route $a, Route $b): float
    {
        return $this->geometry->overlapPercent(self::sampled($a), self::sampled($b));
    }

    public function progressAlongRoute(Route $route, Coordinate $point): float
    {
        return $this->geometry->progressAlongRoute($route, $point);
    }

    public function distanceFromRoute(Route $route, Coordinate $point): Distance
    {
        return $this->geometry->distanceFromRoute($route, $point);
    }

    /**
     * One `computeRoutes` call. Null on any failure, after logging it — the caller decides what
     * stands in.
     *
     * @param  array<int, Coordinate>  $via
     */
    private function compute(Coordinate $from, Coordinate $to, array $via, ?CarbonImmutable $departAt): ?Route
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            Log::warning('geo.google.no_key', ['message' => 'RAFEEQ_GEO_ENGINE=google but GOOGLE_MAPS_API_KEY is empty']);

            return null;
        }

        $body = [
            'origin' => self::waypoint($from),
            'destination' => self::waypoint($to),
            'intermediates' => array_map(self::waypoint(...), array_values($via)),
            'travelMode' => 'DRIVE',
            /*
             * Traffic only when a time was asked for. A plain route is used for distance, search
             * and detour checks, where the time of day is not the question — and TRAFFIC_UNAWARE is
             * both cheaper and stable, so the same commute measures the same at noon and at 8am.
             */
            'routingPreference' => $departAt === null ? 'TRAFFIC_UNAWARE' : 'TRAFFIC_AWARE',
            'polylineEncoding' => 'ENCODED_POLYLINE',
            'units' => 'METRIC',
        ];

        if ($departAt !== null) {
            $body['departureTime'] = $departAt->utc()->format('Y-m-d\TH:i:s\Z');
        }

        if ($body['intermediates'] === []) {
            unset($body['intermediates']);
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->apiKey,
                    'X-Goog-FieldMask' => self::FIELD_MASK,
                ])
                ->acceptJson()
                ->post($this->endpoint, $body);
        } catch (Throwable $e) {
            Log::warning('geo.google.unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('geo.google.error', [
                'status' => $response->status(),
                // Google's own message says which field it disliked; the key is never in it.
                'error' => $response->json('error.message'),
            ]);

            return null;
        }

        $route = $response->json('routes.0');

        if (! is_array($route) || ! isset($route['duration'], $route['polyline']['encodedPolyline'])) {
            // ZERO_RESULTS comes back as `{}`: no road joins these points.
            Log::warning('geo.google.no_route', ['status' => $response->status()]);

            return null;
        }

        return new Route(
            polyline: (string) $route['polyline']['encodedPolyline'],
            // Absent, not zero, when origin and destination coincide.
            distance: Distance::fromMetres((int) ($route['distanceMeters'] ?? 0)),
            // A protobuf Duration: "1234s", sometimes with a fraction.
            durationSeconds: (int) round((float) rtrim((string) $route['duration'], 's')),
        );
    }

    /**
     * @param  array<int, Coordinate>  $via
     */
    private function fallback(Coordinate $from, Coordinate $to, array $via): Route
    {
        $estimate = $this->geometry->routeBetween($from, $to, $via);

        return new Route(
            polyline: $estimate->polyline,
            distance: $estimate->distance,
            durationSeconds: $estimate->durationSeconds,
            points: $estimate->points(),
            isFallback: true,
        );
    }

    /**
     * @return array{location: array{latLng: array{latitude: float, longitude: float}}}
     */
    private static function waypoint(Coordinate $point): array
    {
        return ['location' => ['latLng' => ['latitude' => $point->lat, 'longitude' => $point->lng]]];
    }

    /**
     * Every n-th point, always keeping both ends, so the overlap comparison stays cheap.
     */
    private static function sampled(Route $route): Route
    {
        $points = $route->points();

        if (count($points) <= self::OVERLAP_SAMPLE_POINTS) {
            return $route;
        }

        $step = (int) ceil(count($points) / self::OVERLAP_SAMPLE_POINTS);
        $kept = [];

        foreach ($points as $index => $point) {
            if ($index % $step === 0) {
                $kept[] = $point;
            }
        }

        $kept[] = $points[count($points) - 1];

        return new Route(
            polyline: $route->polyline,
            distance: $route->distance,
            durationSeconds: $route->durationSeconds,
            points: $kept,
        );
    }
}
