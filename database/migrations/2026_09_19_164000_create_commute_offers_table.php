<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commute_offers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_profile_id')->constrained('driver_profiles', 'user_id');
            $table->foreignUlid('vehicle_id')->constrained('vehicles'); // must be approved and active
            $table->foreignUlid('corridor_id')->nullable()->constrained('corridors')->nullOnDelete();
            $table->string('commute_type', 20); // recurring | one_time
            $table->string('status', 20)->default('draft'); // draft|published|paused|archived
            $table->string('direction', 20); // to_work | to_home
            $table->unsignedTinyInteger('seats_total'); // enforced <= vehicle.seats in the Action layer
            $table->unsignedInteger('price_per_seat_piastres');
            $table->char('currency', 3)->default('EGP');
            $table->unsignedTinyInteger('max_detour_minutes');
            $table->unsignedTinyInteger('max_walk_minutes');
            $table->string('audience', 20); // women_only | any_verified
            $table->unsignedTinyInteger('min_trust_level')->default(0);
            $table->boolean('allows_custom_pickup')->default(false);
            $table->text('route_polyline')->nullable(); // computed once at publish
            $table->unsignedInteger('route_distance_meters')->nullable();
            $table->unsignedInteger('route_duration_seconds')->nullable();
            $table->decimal('bbox_min_lat', 10, 7)->nullable(); // THE search index
            $table->decimal('bbox_max_lat', 10, 7)->nullable();
            $table->decimal('bbox_min_lng', 10, 7)->nullable();
            $table->decimal('bbox_max_lng', 10, 7)->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('paused_at')->nullable();
            $table->string('paused_reason', 30)->nullable(); // vehicle_suspended|licence_expired|by_driver
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();

            // ERD §21 — the single most important index in the system.
            $table->index(
                ['status', 'audience', 'bbox_min_lat', 'bbox_max_lat', 'bbox_min_lng', 'bbox_max_lng'],
                'commute_offers_search_index'
            );
            $table->index(['driver_profile_id', 'status']);
            $table->index(['corridor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commute_offers');
    }
};
