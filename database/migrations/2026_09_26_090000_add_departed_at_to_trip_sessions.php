<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the run actually set off.
 *
 * 🔴 A deviation from the ERD's `trip_sessions`, and here is why it is needed. The table
 * has `started_at`, which the Bible's own timeline sets at 06:58 when the driver taps
 * "start" and the run enters `preparing` — that is the moment she picked up her phone,
 * not the moment the car moved. The run leaves when it enters `in_progress`, at 07:08 in
 * the same timeline, and nothing recorded that.
 *
 * Which matters because `driver_profiles.on_time_rate` is shown on three screens as a
 * trust signal, and there was no honest way to compute it: the alternatives are
 * `started_at` (a driver who opens the app early would score as late) or the last
 * passenger's check-in (meaningless on a run where nobody was confirmed). A number that
 * decides whether strangers trust somebody should not be derived from a proxy.
 *
 * Nullable, because a run that is under way has not departed yet and a run that was
 * never started never will.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_sessions', function (Blueprint $table) {
            $table->dateTime('departed_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('trip_sessions', function (Blueprint $table) {
            $table->dropColumn('departed_at');
        });
    }
};
