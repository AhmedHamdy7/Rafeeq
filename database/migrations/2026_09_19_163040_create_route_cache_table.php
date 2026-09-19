<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No FK to anything (Bible §6/ERD §17): a pure cache layer that gets
     * cleaned up freely. Coordinates rounded to 4 decimal places (~11m) so
     * nearby searches share the same cached route — Bible §15.3, the
     * "Google Maps cost isn't optional to cache" section.
     */
    public function up(): void
    {
        Schema::create('route_cache', function (Blueprint $table) {
            $table->string('cache_key', 64)->primary(); // sha256(origin_4dp, dest_4dp, waypoints, mode)
            $table->decimal('origin_lat', 10, 4);
            $table->decimal('origin_lng', 10, 4);
            $table->decimal('dest_lat', 10, 4);
            $table->decimal('dest_lng', 10, 4);
            $table->text('polyline');
            $table->unsignedInteger('distance_meters');
            $table->unsignedInteger('duration_seconds'); // TTL 30 days
            $table->unsignedInteger('duration_in_traffic_seconds')->nullable(); // TTL 1 hour
            $table->string('provider', 20)->default('google');
            $table->dateTime('fetched_at');
            $table->dateTime('expires_at');
            $table->unsignedInteger('hit_count')->default(0); // cache efficiency monitor
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_cache');
    }
};
