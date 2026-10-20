<?php

namespace App\Domains\Trip\Support;

use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Enums\NavigationStopKind;
use Carbon\CarbonImmutable;

/**
 * Puts a set of passenger pickups and drop-offs into the order a driver would actually reach them
 * (Feature 03 v5 §3).
 *
 * Two rules, and v5 asks for exactly these two:
 *
 * 1. **Order by position along the driver's own route.** Not by booking, not by when the seat was
 *    approved. A stop's place in the run is a fact about geography, and the only honest source for
 *    it is how far along the road it sits.
 * 2. **A passenger's pickup always precedes her own drop-off.** Rule 1 can violate this — somebody
 *    travelling a short hop against the general flow of the commute will have her drop-off project
 *    earlier than her pickup — and a route that sets a woman down before collecting her is not a
 *    route.
 *
 * 🔴 Rule 2 is applied by moving the DROP-OFF later, never by moving the pickup earlier. That
 * direction matters and it is what makes one pass sufficient: a pickup never moves, so repairing
 * one booking can never break another booking's invariant. Moving a pickup earlier instead could
 * push it before a drop-off that had already been settled, and the repair would have to loop with
 * no guarantee it terminated.
 *
 * Kept free of Eloquent and of the database on purpose: this is the part with the real reasoning in
 * it, and it is worth being able to test with four coordinates and no fixtures.
 */
final class StopOrder
{
    /**
     * @param  list<array{bookingId: string, kind: NavigationStopKind, point: Coordinate, label: string|null, reachedAt: CarbonImmutable|null}>  $points
     * @return list<array{bookingId: string, kind: NavigationStopKind, point: Coordinate, label: string|null, reachedAt: CarbonImmutable|null}>
     */
    public static function along(
        GeoQueryEngine $geo,
        Coordinate $start,
        Coordinate $end,
        array $points,
    ): array {
        if ($points === []) {
            return [];
        }

        /*
         * The driver's bare journey, with no passenger stops in it, is what every point is measured
         * against. Measuring against the route WITH the stops would be circular — the thing being
         * computed would decide the order it was computed in.
         */
        $spine = $geo->routeBetween($start, $end);

        $withProgress = [];

        foreach ($points as $index => $point) {
            $withProgress[] = [
                'point' => $point,
                'progress' => $geo->progressAlongRoute($spine, $point['point']),
                // Standard #42, applied to a sort rather than a query: a tiebreak that is stable,
                // so two stops at the same position on the road do not swap between rebuilds and
                // make the itinerary look like it changed when nothing did.
                'index' => $index,
            ];
        }

        usort($withProgress, function (array $a, array $b): int {
            if ($a['progress'] !== $b['progress']) {
                return $a['progress'] <=> $b['progress'];
            }

            // Same place on the road: collect before setting down, then fall back to the order they
            // arrived in.
            $aPickup = $a['point']['kind'] === NavigationStopKind::PassengerPickup;
            $bPickup = $b['point']['kind'] === NavigationStopKind::PassengerPickup;

            if ($aPickup !== $bPickup) {
                return $aPickup ? -1 : 1;
            }

            return $a['index'] <=> $b['index'];
        });

        return self::repairPickupBeforeDropoff(
            array_map(fn (array $row): array => $row['point'], $withProgress)
        );
    }

    /**
     * Moves any drop-off that landed before its own pickup to sit immediately after it.
     *
     * @param  list<array{bookingId: string, kind: NavigationStopKind, point: Coordinate, label: string|null, reachedAt: CarbonImmutable|null}>  $ordered
     * @return list<array{bookingId: string, kind: NavigationStopKind, point: Coordinate, label: string|null, reachedAt: CarbonImmutable|null}>
     */
    private static function repairPickupBeforeDropoff(array $ordered): array
    {
        $bookingIds = [];

        foreach ($ordered as $stop) {
            if ($stop['bookingId'] !== null && ! in_array($stop['bookingId'], $bookingIds, true)) {
                $bookingIds[] = $stop['bookingId'];
            }
        }

        foreach ($bookingIds as $bookingId) {
            $pickup = self::indexOf($ordered, $bookingId, NavigationStopKind::PassengerPickup);
            $dropoff = self::indexOf($ordered, $bookingId, NavigationStopKind::PassengerDropoff);

            // A booking with only one of the two cannot be out of order. It should not happen —
            // both points are required on every request — but an itinerary is not the place to
            // throw over it.
            if ($pickup === null || $dropoff === null || $dropoff > $pickup) {
                continue;
            }

            $stop = $ordered[$dropoff];

            array_splice($ordered, $dropoff, 1);

            // Recomputed after the removal: taking the drop-off out shifted everything after it,
            // including the pickup when it sat later in the list.
            $pickupNow = self::indexOf($ordered, $bookingId, NavigationStopKind::PassengerPickup);

            array_splice($ordered, $pickupNow + 1, 0, [$stop]);
        }

        return $ordered;
    }

    /**
     * @param  list<array{bookingId: string, kind: NavigationStopKind, point: Coordinate, label: string|null, reachedAt: CarbonImmutable|null}>  $ordered
     */
    private static function indexOf(array $ordered, string $bookingId, NavigationStopKind $kind): ?int
    {
        foreach ($ordered as $index => $stop) {
            if ($stop['bookingId'] === $bookingId && $stop['kind'] === $kind) {
                return $index;
            }
        }

        return null;
    }
}
