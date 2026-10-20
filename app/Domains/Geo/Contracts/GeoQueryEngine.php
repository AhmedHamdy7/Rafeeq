<?php

namespace App\Domains\Geo\Contracts;

use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;

/**
 * Every geographic question the system asks, behind one door (Bible §3.9).
 *
 * The rule around it is absolute: no `ST_*` expression and no routing-provider
 * call may appear anywhere outside `app/Domains/Geo`. That is not tidiness —
 * it is the only thing that keeps a later move to PostGIS possible, and the
 * only thing that keeps provider cost containable. Pitfall #16 is a search
 * that calls a directions API once per candidate offer: at roughly four
 * dollars per search, five searches by one person is twenty dollars.
 *
 * Implementations: a fake straight-line engine for development and tests, a
 * real provider in production, each wrapped by the caching decorator so a
 * repeated question is never paid for twice.
 */
interface GeoQueryEngine
{
    /**
     * Straight-line distance. Cheap, exact, and never needs a provider — which
     * is why the first pass of any search uses this and not a road route.
     */
    public function distanceMeters(Coordinate $a, Coordinate $b): Distance;

    /**
     * @param  array<int, Coordinate>  $via  intermediate pickup points, in order
     */
    public function routeBetween(Coordinate $from, Coordinate $to, array $via = []): Route;

    /**
     * How much longer the journey becomes if it stops for this pickup. The
     * figure a driver's `max_detour_minutes` is compared against.
     */
    public function detourMinutes(Route $original, Coordinate $pickup): float;

    /**
     * How much of route `$b` runs along route `$a`, as a percentage. Feeds the
     * route-overlap component of the match score.
     */
    public function overlapPercent(Route $a, Route $b): float;

    /**
     * How far along a route a point sits, as a fraction from 0 at the start to 1 at the end.
     *
     * 🔴 This answers a different question from {@see self::distanceFromRoute}, and the navigation
     * itinerary needs both. "How far off the road is she" decides whether to raise a deviation
     * alert; "how far along the road is this stop" is what puts a car full of passengers' pickups
     * and drop-offs into the order a driver would actually reach them (Feature 03 v5 §3).
     *
     * Measured to the closest point ON the line, not to the closest vertex, for the same reason
     * `distanceFromRoute` is: a provider's polyline has a vertex every few hundred metres, and
     * snapping to vertices would quantise every stop on a straight stretch to the same position and
     * destroy the ordering this exists to produce.
     *
     * A point far from the route still gets an answer — the fraction of its nearest approach. That
     * is deliberate: whether a stop is an acceptable detour is a question for the detour check, and
     * returning null here would mean an ordering that silently dropped stops.
     */
    public function progressAlongRoute(Route $route, Coordinate $point): float;

    /**
     * How far a point lies from the nearest part of a route.
     *
     * Not the distance to the nearest of its POINTS — the distance to the nearest
     * point on the line between them. A route encoded every few hundred metres
     * would otherwise report a car driving exactly along it as hundreds of
     * metres off, which is the difference between a working deviation alert and
     * one nobody can trust.
     *
     * Feeds route-deviation detection on a live trip.
     */
    public function distanceFromRoute(Route $route, Coordinate $point): Distance;
}
