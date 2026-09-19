<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔴 The most dangerous pitfall in the whole project (Bible pitfall #6):
     * Egypt actually observes DST by law (since 2023) — the last Friday of
     * April through the last Thursday of October. `departure_time` +
     * `timezone` are stored separately; the UTC instant on `scheduled_trips`
     * is computed at generation time, never stored here.
     */
    public function up(): void
    {
        Schema::create('commute_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commute_offer_id')->constrained('commute_offers')->cascadeOnDelete();
            $table->unsignedTinyInteger('days_mask');
            $table->time('departure_time'); // LOCAL wall clock, NOT UTC
            $table->string('timezone', 40)->default('Africa/Cairo');
            $table->date('start_date');
            $table->date('end_date'); // MANDATORY — no infinite recurrence
            $table->date('generated_until')->nullable(); // rolling horizon
            $table->timestamps();

            $table->index('commute_offer_id');
        });

        // ERD §20 constraint #17 — blocks an inverted schedule window.
        DB::statement('ALTER TABLE commute_schedules ADD CONSTRAINT chk_commute_schedules_range CHECK (end_date >= start_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('commute_schedules');
    }
};
