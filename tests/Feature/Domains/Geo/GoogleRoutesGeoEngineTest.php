<?php

use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Models\RouteCache;
use App\Domains\Geo\Support\CachingGeoEngine;
use App\Domains\Geo\Support\GoogleRoutesGeoEngine;
use App\Domains\Geo\Support\Polyline;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The Google Routes engine (C-2, PAY-42): real road distances and traffic-aware durations.
 *
 * Every call is faked. The suite never talks to Google — straight_line stays the engine every other
 * test runs on — so what is checked here is the request we send, how the answer is read, and the
 * fallback that keeps publishing alive when Google is not answering.
 */
function googleEngine(?string $key = 'test-key'): GoogleRoutesGeoEngine
{
    return new GoogleRoutesGeoEngine(
        apiKey: $key,
        endpoint: 'https://routes.googleapis.com/directions/v2:computeRoutes',
        timeoutSeconds: 5,
    );
}

function googleFrom(): Coordinate
{
    return new Coordinate(30.0594, 31.4913);
}

function googleTo(): Coordinate
{
    return new Coordinate(30.0714, 30.9716);
}

/**
 * @return array<string, mixed>
 */
function googleRoute(int $metres, string $duration): array
{
    return ['routes' => [[
        'distanceMeters' => $metres,
        'duration' => $duration,
        'polyline' => ['encodedPolyline' => Polyline::encode([googleFrom(), googleTo()])],
    ]]];
}

it('reads the road distance and duration Google returns', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(googleRoute(61_250, '3720s'))]);

    $route = googleEngine()->routeBetween(googleFrom(), googleTo());

    expect($route->distance->metres)->toBe(61_250)
        ->and($route->durationSeconds)->toBe(3720)
        ->and($route->isFallback)->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('X-Goog-Api-Key', 'test-key')
            && str_contains($request->header('X-Goog-FieldMask')[0], 'routes.distanceMeters')
            && $request['travelMode'] === 'DRIVE'
            && $request['routingPreference'] === 'TRAFFIC_UNAWARE'
            && ! isset($request['departureTime']);
    });
});

it('sends the riders\' stops as intermediates, in order', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(googleRoute(63_000, '3900s'))]);

    $stop = new Coordinate(30.05, 31.30);

    googleEngine()->routeBetween(googleFrom(), googleTo(), [$stop]);

    Http::assertSent(fn (Request $request): bool => $request['intermediates'][0]['location']['latLng']['latitude'] === 30.05);
});

it('times the route for an arrival using traffic, departing from the arrival minus the duration', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 04:00:00', 'UTC'));

    Http::fakeSequence('routes.googleapis.com/*')
        ->push(googleRoute(61_250, '3600s'))   // the plain route: 60 min
        ->push(googleRoute(61_250, '4500s'))   // traffic from 07:00 UTC: 75 min
        ->push(googleRoute(61_250, '4560s'));  // refined from 06:45 UTC: 76 min

    $arriveBy = CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC');

    $route = googleEngine()->routeArrivingBy(googleFrom(), googleTo(), [], $arriveBy);

    expect($route->durationSeconds)->toBe(4560);

    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);

    expect($sent)->toHaveCount(3)
        ->and($sent[1]['routingPreference'])->toBe('TRAFFIC_AWARE')
        ->and($sent[1]['departureTime'])->toBe('2026-11-01T07:00:00Z')
        ->and($sent[2]['departureTime'])->toBe('2026-11-01T06:45:00Z');

    $this->travelBack();
});

it('does not ask for traffic on a departure that has already gone by', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));

    Http::fake(['routes.googleapis.com/*' => Http::response(googleRoute(61_250, '3600s'))]);

    googleEngine()->routeArrivingBy(googleFrom(), googleTo(), [], CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'));

    Http::assertSentCount(1);

    $this->travelBack();
});

it('falls back to the straight-line estimate when Google fails, and says so', function (mixed $response) {
    Http::fake(['routes.googleapis.com/*' => $response]);

    $route = googleEngine()->routeBetween(googleFrom(), googleTo());

    expect($route->isFallback)->toBeTrue()
        ->and($route->distance->metres)->toBeGreaterThan(0)
        ->and($route->durationSeconds)->toBeGreaterThan(0);
})->with([
    'a server error' => fn () => Http::response(['error' => ['message' => 'boom']], 500),
    'a refused key' => fn () => Http::response(['error' => ['message' => 'API key not valid']], 403),
    'no road between the points' => fn () => Http::response([], 200),
]);

it('never calls Google without a key', function () {
    Http::fake();

    $route = googleEngine(key: '')->routeBetween(googleFrom(), googleTo());

    expect($route->isFallback)->toBeTrue();
    Http::assertNothingSent();
});

it('estimates a pickup detour locally, without paying Google per search candidate', function () {
    Http::fake();

    $route = new Route(
        polyline: Polyline::encode([googleFrom(), googleTo()]),
        distance: Distance::fromMetres(61_250),
        durationSeconds: 3600,
    );

    $onTheWay = googleEngine()->detourMinutes($route, new Coordinate(30.065, 31.23));
    $farOff = googleEngine()->detourMinutes($route, new Coordinate(30.20, 31.23));

    expect($onTheWay)->toBeLessThan($farOff)
        ->and($farOff)->toBeGreaterThan(0.0);

    Http::assertNothingSent();
});

it('never caches a fallback route, so an outage does not leave a month of guesses', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response([], 500)]);

    $engine = new CachingGeoEngine(googleEngine());

    $engine->routeBetween(googleFrom(), googleTo());

    expect(RouteCache::query()->count())->toBe(0);
});

it('caches a timed route by local weekday and clock slice, so next week\'s same morning is free', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 04:00:00', 'UTC'));

    Http::fake(['routes.googleapis.com/*' => Http::response(googleRoute(61_250, '3600s'))]);

    $engine = new CachingGeoEngine(googleEngine());

    $engine->routeArrivingBy(googleFrom(), googleTo(), [], CarbonImmutable::parse('2026-11-01 07:00:00', 'UTC'));
    $calls = count(Http::recorded());

    // Same Sunday, a few minutes apart inside one slice: served from the cache.
    $engine->routeArrivingBy(googleFrom(), googleTo(), [], CarbonImmutable::parse('2026-11-01 07:05:00', 'UTC'));

    expect(count(Http::recorded()))->toBe($calls)
        ->and(CachingGeoEngine::trafficCacheKey(googleFrom(), googleTo(), [], CarbonImmutable::parse('2026-11-01 07:00:00', 'UTC')))
        ->toBe(CachingGeoEngine::trafficCacheKey(googleFrom(), googleTo(), [], CarbonImmutable::parse('2026-11-08 07:00:00', 'UTC')));

    $this->travelBack();
});

it('is the engine the container hands out when RAFEEQ_GEO_ENGINE=google', function () {
    config(['rafeeq.geo.engine' => 'google', 'services.google_maps.key' => 'k']);
    app()->forgetInstance(GeoQueryEngine::class);

    Http::fake(['routes.googleapis.com/*' => Http::response(googleRoute(61_250, '3600s'))]);

    $route = app(GeoQueryEngine::class)->routeBetween(googleFrom(), googleTo());

    expect($route->distance->metres)->toBe(61_250);
    Http::assertSentCount(1);
});
