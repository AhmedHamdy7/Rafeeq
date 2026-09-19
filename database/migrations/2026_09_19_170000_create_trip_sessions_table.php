<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('scheduled_trip_id')->unique()->constrained('scheduled_trips'); // one session per trip
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('current_status', 25)->default('preparing'); // preparing|en_route|at_pickup|in_progress|completed|cancelled|emergency
            $table->unsignedInteger('distance_travelled_meters')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->dateTime('deviation_detected_at')->nullable(); // route-deviation alert
            $table->unsignedInteger('deviation_distance_meters')->nullable();
            $table->dateTime('last_location_at')->nullable(); // GPS dropout detection
            $table->timestamps();

            $table->index('current_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_sessions');
    }
};
