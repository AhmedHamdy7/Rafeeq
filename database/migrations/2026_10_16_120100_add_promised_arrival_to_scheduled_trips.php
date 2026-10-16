<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The arrival the driver promised for this particular day (Feature 03 v3).
 *
 * Snapshotted onto the occurrence beside `departure_at` and the price, and for the same reason: the
 * promise a passenger accepted when she booked is the one she is owed, even if the driver later
 * edits the weekday it came from.
 *
 * 🔴 Product decision, 2026-10-16: **arriving later than this counts against the driver's on-time
 * rate**, outside a five-minute tolerance. So this column is not decorative — it is the figure a
 * score is computed against, which is why it is stored per occurrence rather than read live from
 * the weekday row.
 *
 * Nullable, and null for every commute published before this existed. A driver who never made a
 * promise cannot break one: no promise, no breach, and nothing counts against her. Inventing a
 * plausible arrival for historical commutes would have handed them a score they never agreed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->dateTime('promised_arrival_at')->nullable()->after('departure_local');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->dropColumn('promised_arrival_at');
        });
    }
};
