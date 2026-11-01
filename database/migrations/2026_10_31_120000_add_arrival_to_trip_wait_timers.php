<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "I've arrived", per passenger, checked by GPS (TRIP-10, TRIP-11; decision C14 of 2026-10-31).
 *
 * The wait timer used to start wherever the driver happened to be when she tapped it, and its grace
 * ran from that tap. Fees now hang off that grace — lateness, no-show — so two facts have to be on
 * the record beside it:
 *
 * - `arrived_at` + `arrival_distance_meters`: when the driver said she had arrived and how far from
 *   THIS passenger's pickup the server measured her. The arrival is refused beyond 150 m
 *   (`trip.arrival_radius_meters`); the distance is kept because "she was 140 m away" and "she was
 *   at the kerb" are different mornings in a dispute.
 * - `grace_starts_at`: the later of that arrival and the passenger's own pickup time. A driver who
 *   turns up ten minutes early does not start a passenger's five minutes ten minutes early.
 *
 * 🔴 Columns on the timer rather than a table of their own, because an arrival IS the start of the
 * wait: one row per (run, passenger, arrival), and the timer's `expiresAt()` is read off exactly
 * these. A separate arrivals table would be a second place to ask "when did her grace start", and
 * the day the two disagreed would be the day somebody was charged against the wrong one.
 *
 * Nullable for the rows written before this — those started at `started_at`, and `expiresAt()`
 * still falls back to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_wait_timers', function (Blueprint $table) {
            $table->dateTime('arrived_at')->nullable()->after('started_at');
            $table->unsignedInteger('arrival_distance_meters')->nullable()->after('arrived_at');
            $table->dateTime('grace_starts_at')->nullable()->after('arrival_distance_meters');
        });
    }

    public function down(): void
    {
        Schema::table('trip_wait_timers', function (Blueprint $table) {
            $table->dropColumn(['arrived_at', 'arrival_distance_meters', 'grace_starts_at']);
        });
    }
};
