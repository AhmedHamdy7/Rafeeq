<?php

use App\Domains\Booking\Enums\PointSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every seat request now carries the passenger's own pickup AND drop-off point (Feature 03 v5 §2).
 *
 * > *"To request a seat, the passenger MUST choose both a Pickup Point and a Drop-off Point. Each
 * > has map coordinates and label. They cannot be omitted, inferred from the driver's route
 * > endpoints, or silently defaulted."*
 *
 * 🔴 What this replaces. The table had `meeting_preference` (gate/street/landmark/custom) and an
 * optional `custom_pickup_place_id` — a pickup HINT, optional, and no drop-off at all. A driver
 * approving a request therefore agreed to collect somebody without knowing from where, and to carry
 * her without knowing to where; the drop-off was assumed to be the commute's destination, which is
 * exactly the inference v5 forbids. `meeting_preference` is kept: it still says what KIND of place
 * she would rather meet at, which is a different question from which point.
 *
 * 🔴 **Why NOT NULL, given there are already rows.** v5 says NOT NULL and the reason is that every
 * reader downstream — the navigation itinerary, the detour maths, the driver's review screen —
 * would otherwise need a "what if she never said" branch, and each of those branches is a place to
 * quietly re-introduce the inference. So the columns are added nullable, every existing row is
 * backfilled from its commute's own start and end, and only then are they made NOT NULL.
 *
 * The backfill is marked `legacy_backfill` and never `custom_map`. It is the most conservative
 * reading of an old request — she rides the driver's whole route — and marking it means a driver
 * reviewing one of these can see that nobody actually chose these points. Writing `custom_map`
 * would have made a migration's guess indistinguishable from a woman's decision for ever.
 *
 * A request on a commute with no route cannot be backfilled and cannot be answered either: there is
 * nothing to pick points along. Those rows are deleted, and there should be none — a seat cannot be
 * requested on an unpublished commute, and publishing requires a route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            foreach (['pickup', 'dropoff'] as $kind) {
                // Same shape as `commute_locations`: the spatial column for geographic queries,
                // plus the decimals, which are what every payload and every distance calculation
                // in this codebase actually reads.
                $table->geography("requested_{$kind}_point", subtype: 'point', srid: 4326)->nullable();
                $table->decimal("requested_{$kind}_lat", 10, 7)->nullable();
                $table->decimal("requested_{$kind}_lng", 10, 7)->nullable();
                // The readable place name v5 requires beside the coordinates. A pin with no label
                // is unreadable on a driver's review screen and in a notification.
                $table->string("requested_{$kind}_label", 255)->nullable();
                $table->string("requested_{$kind}_source", 20)->nullable();
                /*
                 * Which advertised point she picked, when she picked one. Nullable for a custom
                 * map point, and `nullOnDelete` because a driver removing a suggestion from her
                 * route must not delete the request of somebody who chose it — the coordinates
                 * above are the authoritative record of what was agreed.
                 */
                $table->foreignUlid("requested_{$kind}_suggestion_id")
                    ->nullable()
                    ->constrained('commute_locations')
                    ->nullOnDelete();
            }
        });

        $this->backfillFromCommuteRoutes();

        Schema::table('seat_requests', function (Blueprint $table) {
            foreach (['pickup', 'dropoff'] as $kind) {
                $table->geography("requested_{$kind}_point", subtype: 'point', srid: 4326)->nullable(false)->change();
                $table->decimal("requested_{$kind}_lat", 10, 7)->nullable(false)->change();
                $table->decimal("requested_{$kind}_lng", 10, 7)->nullable(false)->change();
                $table->string("requested_{$kind}_label", 255)->nullable(false)->change();
                $table->string("requested_{$kind}_source", 20)->nullable(false)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            foreach (['pickup', 'dropoff'] as $kind) {
                $table->dropConstrainedForeignId("requested_{$kind}_suggestion_id");
                $table->dropColumn([
                    "requested_{$kind}_point",
                    "requested_{$kind}_lat",
                    "requested_{$kind}_lng",
                    "requested_{$kind}_label",
                    "requested_{$kind}_source",
                ]);
            }
        });
    }

    /**
     * Pickup from the commute's origin, drop-off from its destination — the whole route, which is
     * the most she could have been promised under the old shape.
     */
    private function backfillFromCommuteRoutes(): void
    {
        DB::table('seat_requests')
            ->whereNull('requested_pickup_lat')
            ->orderBy('id')
            ->each(function (object $request): void {
                $origin = $this->locationOf($request->commute_offer_id, 'origin');
                $destination = $this->locationOf($request->commute_offer_id, 'destination');

                if ($origin === null || $destination === null) {
                    // No route to pick points along, so no request anybody could answer.
                    DB::table('seat_requests')->where('id', $request->id)->delete();

                    return;
                }

                DB::table('seat_requests')->where('id', $request->id)->update([
                    'requested_pickup_point' => DB::raw(self::pointSql($origin)),
                    'requested_pickup_lat' => $origin->lat,
                    'requested_pickup_lng' => $origin->lng,
                    'requested_pickup_label' => $origin->address ?? 'Route start',
                    'requested_pickup_source' => PointSource::LegacyBackfill->value,

                    'requested_dropoff_point' => DB::raw(self::pointSql($destination)),
                    'requested_dropoff_lat' => $destination->lat,
                    'requested_dropoff_lng' => $destination->lng,
                    'requested_dropoff_label' => $destination->address ?? 'Route end',
                    'requested_dropoff_source' => PointSource::LegacyBackfill->value,
                ]);
            });
    }

    private function locationOf(string $commuteOfferId, string $type): ?object
    {
        return DB::table('commute_locations')
            ->where('commute_offer_id', $commuteOfferId)
            ->where('type', $type)
            ->orderBy('sequence')
            ->first(['lat', 'lng', 'address']);
    }

    /**
     * 🔴 The one place in this change that writes an `ST_*` expression, and it is a migration
     * rather than application code — the rule that keeps routing providers and PostGIS portability
     * behind `app/Domains/Geo` is about the running system, and a backfill has no engine to ask.
     *
     * Coordinates are cast to float and interpolated rather than bound because `DB::raw` inside an
     * `update` array takes no bindings. They come from a numeric column one line above, never from
     * a request.
     */
    private static function pointSql(object $location): string
    {
        return sprintf(
            'ST_SRID(POINT(%F, %F), 4326)',
            (float) $location->lng,
            (float) $location->lat,
        );
    }
};
