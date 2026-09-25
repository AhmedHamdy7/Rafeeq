<?php

namespace App\Domains\Geo\Actions;

use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Shared\ValueObjects\Distance;

/**
 * Finds the known corridor a journey belongs to, if any.
 *
 * A corridor is a route the platform has named because many people travel it at
 * a similar time — Rehab to Smart Village, Sunday to Thursday, 6:45 to 8:00.
 * It is not derived from one commute; it is how supply and demand are reasoned
 * about in aggregate, which is what the health statuses and the escort windows
 * in later phases hang off.
 *
 * Matching is intentionally loose on geography and strict on time. Two people
 * leaving the same compound gate ten minutes apart are on the same corridor;
 * two leaving it twelve hours apart are not making the same journey at all.
 */
final readonly class MatchCorridorAction
{
    public function __construct(private GeoQueryEngine $geo) {}

    public function execute(
        Coordinate $origin,
        Coordinate $destination,
        string $departureTime,
        int $daysMask,
    ): ?Corridor {
        $tolerance = Distance::fromMetres((int) config('rafeeq.geo.corridor_match_metres'));

        // Narrowed in SQL by the time window and the day overlap first, so the
        // distance comparisons run over a handful of rows rather than the table.
        // `days_mask & ?` is why the mask is an integer and not JSON.
        $candidates = Corridor::query()
            ->with(['originPlace', 'destinationPlace'])
            ->where('window_start', '<=', $departureTime)
            ->where('window_end', '>=', $departureTime)
            ->whereRaw('(days_mask & ?) > 0', [$daysMask])
            ->get();

        $best = null;
        $bestMetres = PHP_INT_MAX;

        foreach ($candidates as $corridor) {
            $originGap = $this->geo->distanceMeters($origin, $this->pointOf($corridor->originPlace));
            $destinationGap = $this->geo->distanceMeters($destination, $this->pointOf($corridor->destinationPlace));

            if (! $originGap->isWithin($tolerance) || ! $destinationGap->isWithin($tolerance)) {
                continue;
            }

            // Closest wins, so overlapping corridors resolve deterministically
            // rather than by whichever row came back first.
            $total = $originGap->metres + $destinationGap->metres;

            if ($total < $bestMetres) {
                $best = $corridor;
                $bestMetres = $total;
            }
        }

        return $best;
    }

    private function pointOf(mixed $place): Coordinate
    {
        // The duplicated lat/lng columns, not the spatial column: they exist
        // precisely so arithmetic like this needs no spatial function.
        return new Coordinate((float) $place->lat, (float) $place->lng);
    }

    /**
     * Whether a corridor runs on any of the given days. Kept here so callers
     * never open the bitmask themselves.
     */
    public static function runsOnAnyOf(Corridor $corridor, DaysMask $days): bool
    {
        return ($corridor->days_mask & $days->value) > 0;
    }
}
