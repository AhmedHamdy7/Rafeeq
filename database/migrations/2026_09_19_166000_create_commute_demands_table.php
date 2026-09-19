<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔒 Secret by design (Bible §8, ERD group ⑥): this is what makes
     * Rafeeq different from "an auction on passengers" — no endpoint may
     * ever return this table, or a row from it, to a driver. Enforced from
     * Phase 6 onward at the API layer; there is nothing to route yet in
     * Phase 1, but every future search/matching controller must respect it.
     */
    public function up(): void
    {
        Schema::create('commute_demands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('passenger_user_id')->constrained('users')->cascadeOnDelete();
            $table->geography('origin_point', subtype: 'point', srid: 4326);
            $table->geography('destination_point', subtype: 'point', srid: 4326);
            $table->decimal('origin_lat', 10, 7);
            $table->decimal('origin_lng', 10, 7);
            $table->decimal('dest_lat', 10, 7);
            $table->decimal('dest_lng', 10, 7);
            $table->string('origin_label', 150)->nullable();
            $table->string('destination_label', 150)->nullable();
            $table->string('commute_type', 20); // recurring | one_time
            $table->unsignedTinyInteger('days_mask');
            $table->time('preferred_arrival_start');
            $table->time('preferred_arrival_end');
            $table->unsignedTinyInteger('flexibility_minutes')->default(15);
            $table->unsignedTinyInteger('max_walk_minutes');
            $table->unsignedTinyInteger('max_detour_minutes');
            $table->unsignedInteger('budget_monthly_piastres')->nullable();
            $table->string('audience_preference', 20);
            $table->boolean('wants_return_trip')->default(false); // evening leg toggle in the UI
            $table->string('status', 20)->default('active'); // active|matched|expired|cancelled
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('last_notified_at')->nullable(); // anti-spam
            $table->timestamps();

            $table->index('passenger_user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commute_demands');
    }
};
