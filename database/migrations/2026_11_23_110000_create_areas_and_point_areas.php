<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->string('google_place_id', 255)->primary();
            $table->string('name_en', 150)->nullable();
            $table->string('name_ar', 150)->nullable();
            $table->string('city_en', 150)->nullable();
            $table->string('city_ar', 150)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->json('viewport')->nullable();
            $table->string('group_id', 255)->nullable()->index();
            $table->timestamps();
        });

        Schema::table('commute_locations', function (Blueprint $table) {
            $table->string('google_place_id', 255)->nullable()->after('place_id');
            $table->string('area_id', 255)->nullable()->after('google_place_id');
            $table->foreign('area_id')->references('google_place_id')->on('areas')->nullOnDelete();
        });

        Schema::table('commute_demands', function (Blueprint $table) {
            foreach (['origin', 'destination'] as $end) {
                $table->string("{$end}_google_place_id", 255)->nullable();
                $table->string("{$end}_area_id", 255)->nullable();
                $table->foreign("{$end}_area_id")->references('google_place_id')->on('areas')->nullOnDelete();
            }
        });

        Schema::table('seat_requests', function (Blueprint $table) {
            foreach (['requested', 'counteroffer'] as $prefix) {
                foreach (['pickup', 'dropoff'] as $kind) {
                    $table->string("{$prefix}_{$kind}_google_place_id", 255)->nullable();
                    $table->string("{$prefix}_{$kind}_area_id", 255)->nullable();
                    $table->foreign("{$prefix}_{$kind}_area_id")->references('google_place_id')->on('areas')->nullOnDelete();
                }
            }
        });

        Schema::table('pickup_point_requests', function (Blueprint $table) {
            $table->string('proposed_google_place_id', 255)->nullable();
            $table->string('proposed_area_id', 255)->nullable();
            $table->foreign('proposed_area_id')->references('google_place_id')->on('areas')->nullOnDelete();
            $table->decimal('alternative_lat', 10, 7)->nullable();
            $table->decimal('alternative_lng', 10, 7)->nullable();
            $table->string('alternative_label', 255)->nullable();
            $table->string('alternative_google_place_id', 255)->nullable();
            $table->string('alternative_area_id', 255)->nullable();
            $table->foreign('alternative_area_id')->references('google_place_id')->on('areas')->nullOnDelete();
        });

        Schema::table('match_notifications', function (Blueprint $table) {
            $table->string('match_kind', 20)->default('near');
            $table->string('area_id', 255)->nullable();
            $table->foreign('area_id')->references('google_place_id')->on('areas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('match_notifications', function (Blueprint $table) {
            $table->dropForeign(['area_id']);
            $table->dropColumn(['match_kind', 'area_id']);
        });

        Schema::table('pickup_point_requests', function (Blueprint $table) {
            $table->dropForeign(['proposed_area_id']);
            $table->dropForeign(['alternative_area_id']);
            $table->dropColumn([
                'proposed_google_place_id', 'proposed_area_id', 'alternative_lat', 'alternative_lng',
                'alternative_label', 'alternative_google_place_id', 'alternative_area_id',
            ]);
        });

        Schema::table('seat_requests', function (Blueprint $table) {
            foreach (['requested', 'counteroffer'] as $prefix) {
                foreach (['pickup', 'dropoff'] as $kind) {
                    $table->dropForeign(["{$prefix}_{$kind}_area_id"]);
                    $table->dropColumn(["{$prefix}_{$kind}_google_place_id", "{$prefix}_{$kind}_area_id"]);
                }
            }
        });

        Schema::table('commute_demands', function (Blueprint $table) {
            foreach (['origin', 'destination'] as $end) {
                $table->dropForeign(["{$end}_area_id"]);
                $table->dropColumn(["{$end}_google_place_id", "{$end}_area_id"]);
            }
        });

        Schema::table('commute_locations', function (Blueprint $table) {
            $table->dropForeign(['area_id']);
            $table->dropColumn(['google_place_id', 'area_id']);
        });

        Schema::dropIfExists('areas');
    }
};
