<?php

use App\Domains\Geo\Support\Polyline;
use App\Domains\Geo\Support\StraightLineGeoEngine;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs commute routes that cannot be decoded.
 *
 * The seeders stored a pasted polyline — a truncated example from the encoding's documentation —
 * that decodes to a latitude of 1232. Every search on the seeded corridor answered 500 with that
 * number in the message. The seeders are fixed; this fixes the rows they already wrote.
 *
 * Each unreadable route is rebuilt from the commute's own stops, in order, by the same engine
 * publishing uses. A readable route is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $engine = app(StraightLineGeoEngine::class);

        DB::table('commute_offers')
            ->whereNotNull('route_polyline')
            ->orderBy('id')
            ->each(function (object $offer) use ($engine): void {
                if (Polyline::isReadable($offer->route_polyline)) {
                    return;
                }

                $stops = DB::table('commute_locations')
                    ->where('commute_offer_id', $offer->id)
                    ->orderBy('sequence')
                    ->get(['lat', 'lng'])
                    ->map(fn (object $stop) => new Coordinate((float) $stop->lat, (float) $stop->lng))
                    ->all();

                // Nothing to rebuild from: clear it, so readers see "no route" rather than garbage.
                if (count($stops) < 2) {
                    DB::table('commute_offers')->where('id', $offer->id)->update(['route_polyline' => null]);

                    return;
                }

                $route = $engine->routeBetween($stops[0], $stops[count($stops) - 1], array_slice($stops, 1, -1));

                DB::table('commute_offers')->where('id', $offer->id)->update([
                    'route_polyline' => $route->polyline,
                    'route_distance_meters' => $route->distance->metres,
                    'route_duration_seconds' => $route->durationSeconds,
                ]);
            });
    }

    public function down(): void
    {
        // A repaired route is not put back to one that crashes search.
    }
};
