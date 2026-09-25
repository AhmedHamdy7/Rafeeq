<?php

use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Models\RouteCache;
use App\Domains\Geo\Support\Haversine;
use App\Domains\Geo\Support\Polyline;
use App\Domains\Geo\ValueObjects\BoundingBox;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use App\Domains\Shared\ValueObjects\WalkTime;

/**
 * Binding standard #7 and Bible §3.9: every geographic question goes through
 * one door, so a later move to PostGIS is a change in one place.
 *
 * The reference journey throughout is the one the Bible uses — Rehab City to
 * Smart Village, about 32km across Cairo.
 */
function rehab(): Coordinate
{
    return new Coordinate(30.0594, 31.4913);
}

function smartVillage(): Coordinate
{
    return new Coordinate(30.0714, 30.9716);
}

it('measures the distance across Cairo to within a sensible margin', function () {
    $distance = app(GeoQueryEngine::class)->distanceMeters(rehab(), smartVillage());

    // Straight-line Rehab → Smart Village is ~50km; the road is longer.
    expect($distance->kilometres())->toBeGreaterThan(45)
        ->and($distance->kilometres())->toBeLessThan(55);
});

it('returns zero for a point measured against itself', function () {
    expect(Haversine::between(rehab(), rehab())->metres)->toBe(0);
});

it('builds a route whose polyline decodes back to the points it was given', function () {
    $route = app(GeoQueryEngine::class)->routeBetween(rehab(), smartVillage());

    $decoded = Polyline::decode($route->polyline);

    expect($decoded)->toHaveCount(2)
        // Five decimal places of precision is ~1m — the format's own limit.
        ->and($decoded[0]->lat)->toBeGreaterThan(30.0593)
        ->and($decoded[0]->lat)->toBeLessThan(30.0595)
        ->and($route->durationSeconds)->toBeGreaterThan(0);
});

it('round-trips a polyline with many points, including negative deltas', function () {
    $points = [
        new Coordinate(30.0594, 31.4913),
        new Coordinate(30.0800, 31.3000),   // north then west
        new Coordinate(30.0500, 31.1000),   // back south
        new Coordinate(30.0714, 30.9716),
    ];

    $decoded = Polyline::decode(Polyline::encode($points));

    expect($decoded)->toHaveCount(4);

    foreach ($points as $index => $point) {
        expect(round($decoded[$index]->lat, 5))->toBe(round($point->lat, 5))
            ->and(round($decoded[$index]->lng, 5))->toBe(round($point->lng, 5));
    }
});

it('threads a route through its waypoints in order', function () {
    $via = new Coordinate(30.0650, 31.2000);

    $direct = app(GeoQueryEngine::class)->routeBetween(rehab(), smartVillage());
    $withStop = app(GeoQueryEngine::class)->routeBetween(rehab(), smartVillage(), [$via]);

    expect($withStop->points())->toHaveCount(3)
        // A detour cannot be shorter than going straight there.
        ->and($withStop->distance->metres)->toBeGreaterThanOrEqual($direct->distance->metres);
});

/**
 * Pitfall #18. A box built from only the endpoints misses any route that bows
 * outward — and an offer outside its own search box is invisible to a passenger
 * standing next to it, which nothing downstream can recover from.
 */
it('builds a bounding box that contains every point of the route, not just its ends', function () {
    $northOfBoth = new Coordinate(30.2000, 31.2000);

    $route = app(GeoQueryEngine::class)->routeBetween(rehab(), smartVillage(), [$northOfBoth]);

    $box = $route->boundingBox(Distance::fromMetres(0));

    expect($box->contains($northOfBoth))->toBeTrue()
        ->and($box->contains(rehab()))->toBeTrue()
        ->and($box->contains(smartVillage()))->toBeTrue()
        // And the bulge really is outside the endpoints' own extent, so this
        // case would fail with an endpoints-only box.
        ->and($northOfBoth->lat)->toBeGreaterThan(max(rehab()->lat, smartVillage()->lat));
});

it('widens the box by the margin it was given', function () {
    $route = app(GeoQueryEngine::class)->routeBetween(rehab(), smartVillage());

    $tight = $route->boundingBox(Distance::fromMetres(0));
    $loose = $route->boundingBox(Distance::fromKilometres(2));

    expect($loose->minLat)->toBeLessThan($tight->minLat)
        ->and($loose->maxLng)->toBeGreaterThan($tight->maxLng);

    // A point 1km north of the route is outside the tight box and inside the
    // loose one.
    $nearby = new Coordinate($tight->maxLat + 0.009, ($tight->minLng + $tight->maxLng) / 2);

    expect($tight->contains($nearby))->toBeFalse()
        ->and($loose->contains($nearby))->toBeTrue();
});

/**
 * A degree of longitude is shorter than a degree of latitude away from the
 * equator. Using the same margin for both would leave the box too narrow
 * east-to-west — ~14% at Cairo's latitude, enough to lose an offer at the edge.
 */
it('accounts for longitude degrees being shorter than latitude degrees', function () {
    $box = BoundingBox::around([rehab()], Distance::fromKilometres(10));

    $latSpan = $box->maxLat - $box->minLat;
    $lngSpan = $box->maxLng - $box->minLng;

    expect($lngSpan)->toBeGreaterThan($latSpan);
});

it('reports a detour for a pickup off the direct path, and none for one on it', function () {
    $engine = app(GeoQueryEngine::class);

    $route = $engine->routeBetween(rehab(), smartVillage());

    $farOff = $engine->detourMinutes($route, new Coordinate(30.4000, 31.3000));
    $onTheWay = $engine->detourMinutes($route, new Coordinate(30.0650, 31.2300));

    expect($farOff)->toBeGreaterThan($onTheWay)
        ->and($onTheWay)->toBeGreaterThanOrEqual(0.0);
});

it('reports full overlap for the same route and little for an unrelated one', function () {
    $engine = app(GeoQueryEngine::class);

    $commute = $engine->routeBetween(rehab(), smartVillage());
    $same = $engine->routeBetween(rehab(), smartVillage());
    $elsewhere = $engine->routeBetween(
        new Coordinate(31.2001, 29.9187),  // Alexandria
        new Coordinate(31.2650, 32.3019),  // Port Said
    );

    expect($engine->overlapPercent($commute, $same))->toBe(100.0)
        ->and($engine->overlapPercent($commute, $elsewhere))->toBeLessThan(50.0);
});

/**
 * Bible §15.3: caching routes is not optional. A routing provider is billed per
 * call and the same commute is looked up over and over.
 */
it('answers a repeated route from the cache instead of the engine', function () {
    $engine = app(GeoQueryEngine::class);

    $engine->routeBetween(rehab(), smartVillage());

    expect(RouteCache::count())->toBe(1);

    $engine->routeBetween(rehab(), smartVillage());

    expect(RouteCache::count())->toBe(1)
        // Counted so cache efficiency is observable rather than assumed.
        ->and(RouteCache::sole()->hit_count)->toBe(1);
});

/**
 * Two drivers pinning the same compound gate will not produce identical floats.
 * Rounding to ~11m before keying is what makes the cache hit at all.
 */
it('shares a cache entry between two points a few metres apart', function () {
    $engine = app(GeoQueryEngine::class);

    $engine->routeBetween(rehab(), smartVillage());
    $engine->routeBetween(new Coordinate(30.05941, 31.49132), smartVillage());

    expect(RouteCache::count())->toBe(1);
});

it('does not reuse a cache entry once it has expired', function () {
    $engine = app(GeoQueryEngine::class);

    $engine->routeBetween(rehab(), smartVillage());

    $this->travel((int) config('rafeeq.geo.route_cache_days') + 1)->days();

    $engine->routeBetween(rehab(), smartVillage());

    // Same key, refreshed rather than duplicated — and not served stale.
    expect(RouteCache::count())->toBe(1)
        ->and(RouteCache::sole()->hit_count)->toBe(0)
        ->and(RouteCache::sole()->expires_at->isFuture())->toBeTrue();
});

it('keys a route with waypoints separately from one without', function () {
    $engine = app(GeoQueryEngine::class);

    $engine->routeBetween(rehab(), smartVillage());
    $engine->routeBetween(rehab(), smartVillage(), [new Coordinate(30.0650, 31.2000)]);

    expect(RouteCache::count())->toBe(2);
});

/**
 * Pitfall #19: `max_walk_minutes = 15` compared against `distance = 1200` is a
 * comparison that looks fine and is wrong by a factor of eighty. Separate types
 * make it a language error rather than a review miss.
 */
it('keeps distance and walking time as separate types', function () {
    $distance = Distance::fromMetres(1200);

    expect($distance->asWalkTime())->toBeInstanceOf(WalkTime::class)
        // 1.2km at 5km/h is about 15 minutes.
        ->and($distance->asWalkTime()->minutes)->toBe(15)
        ->and($distance->asWalkTime()->isWithin(WalkTime::fromMinutes(15)))->toBeTrue()
        ->and($distance->asWalkTime()->isWithin(WalkTime::fromMinutes(10)))->toBeFalse();
});

it('refuses a coordinate that is not on the earth', function () {
    expect(fn () => new Coordinate(91.0, 31.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Coordinate(30.0, 181.0))->toThrow(InvalidArgumentException::class);
});

it('refuses a negative distance or walk time', function () {
    expect(fn () => Distance::fromMetres(-1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WalkTime::fromMinutes(-1))->toThrow(InvalidArgumentException::class);
});
