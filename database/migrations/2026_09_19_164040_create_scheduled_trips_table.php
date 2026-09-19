<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per calendar day, generated from a `commute_schedule` on a
     * rolling 30-day horizon (Bible §Part 2 — never all the way to
     * end_date, or 5,000 offers × ~110 workdays becomes 550,000 rows on day
     * one). This is the fastest-growing table in the system (ERD §22: ~1.3M
     * rows/year) and the one every booking ultimately locks against.
     */
    public function up(): void
    {
        Schema::create('scheduled_trips', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // RESTRICT (default, no cascade): archived after 6 months per
            // ERD §18, never hard-deleted alongside its offer.
            $table->foreignUlid('commute_offer_id')->constrained('commute_offers');
            $table->foreignUlid('commute_schedule_id')->constrained('commute_schedules');
            $table->date('trip_date'); // the LOCAL day
            $table->dateTime('departure_at'); // UTC, computed per-DST at generation time
            $table->dateTime('departure_local'); // kept for display and debugging
            $table->unsignedTinyInteger('seats_total'); // snapshot — later offer edits don't retroactively change it
            $table->unsignedTinyInteger('seats_taken')->default(0); // the column every booking locks
            $table->unsignedInteger('price_snapshot_piastres');
            $table->string('status', 25)->default('scheduled'); // scheduled|preparing|en_route|in_progress|completed|cancelled
            $table->string('cancelled_reason', 255)->nullable();
            $table->dateTime('booking_deadline_at')->nullable(); // 9pm the night before
            $table->timestamps();

            // 🔴 ERD §20 constraint #6 — blocks the generator running twice
            // (retry, manual run) from creating two trips for the same day.
            $table->unique(['commute_offer_id', 'trip_date']);
            $table->index(['departure_at', 'status']);
            $table->index(['status', 'trip_date']);
        });

        // 🔴 ERD §20 constraint #14 — no fluent Blueprint helper for CHECK
        // exists in this Laravel version, so it's added directly.
        DB::statement('ALTER TABLE scheduled_trips ADD CONSTRAINT chk_scheduled_trips_seats CHECK (seats_taken <= seats_total)');
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_trips');
    }
};
