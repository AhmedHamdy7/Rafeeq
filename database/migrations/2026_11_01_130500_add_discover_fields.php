<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the passenger Discover screens show that nothing stored yet (DISCOVER, 2026-11-01).
 *
 * - **How many ratings an average is made of** (DISCOVER-4). The card reads "4.9 ★ (210)", and
 *   a 4.9 from three rides is not the same claim as a 4.9 from two hundred. Kept beside the
 *   averages and recomputed with them on reveal, never on submit, for the reason the averages are:
 *   a count that moved when a hidden rating arrived would say a rating exists before it may be
 *   read.
 * - **A demand's preferences and catalogue places** (DISCOVER-9). Step 1 of the demand form asks
 *   for Women-only and the comfort chips, the same as the search, and until now the demand
 *   threw them away — so the alerts it sent ignored exactly what she had asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_stats', function (Blueprint $table) {
            $table->unsignedInteger('rating_count_as_driver')->default(0)->after('avg_rating_as_driver');
            $table->unsignedInteger('rating_count_as_passenger')->default(0)->after('avg_rating_as_passenger');
        });

        Schema::table('commute_demands', function (Blueprint $table) {
            // A catalogue place when she picked one from `GET /places`; null for a dropped pin.
            $table->ulid('origin_place_id')->nullable()->after('origin_label');
            $table->ulid('destination_place_id')->nullable()->after('destination_label');
            $table->boolean('women_only')->default(false)->after('audience_preference');
            $table->json('required_rules')->nullable()->after('women_only');
            $table->string('vehicle_tier', 20)->nullable()->after('required_rules');
        });

        /*
         * Backfilled from the same rows the averages are computed from, with the same three
         * conditions as `RecomputeRatingStatsAction`: revealed, about a completed ride, and not
         * taken down. A count that disagreed with its own average about which ratings exist would
         * be worse than no count.
         */
        foreach (['rating_count_as_driver' => 'passenger_to_driver', 'rating_count_as_passenger' => 'driver_to_passenger'] as $column => $direction) {
            DB::table('user_stats')->update([
                $column => DB::raw("(select count(*) from ratings
                    where ratings.reviewed_user_id = user_stats.user_id
                    and ratings.direction = '{$direction}'
                    and ratings.kind = 'completed_ride'
                    and ratings.visible_at is not null
                    and ratings.moderation_status != 'hidden')"),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('commute_demands', function (Blueprint $table) {
            $table->dropColumn(['origin_place_id', 'destination_place_id', 'women_only', 'required_rules', 'vehicle_tier']);
        });

        Schema::table('user_stats', function (Blueprint $table) {
            $table->dropColumn(['rating_count_as_driver', 'rating_count_as_passenger']);
        });
    }
};
