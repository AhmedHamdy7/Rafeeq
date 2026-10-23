<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔴 A real bug, found while building `POST /seat-offers/{id}/decline`.
 *
 * `seat_offers` was unique on `(scheduled_trip_id, seat_request_id)`, and the comment above it said
 * *"one **open** offer per person per journey"*. The index says something much stronger: one offer
 * per person per journey **ever**, open or closed. Those are not the same rule, and the difference
 * empties cars.
 *
 * Tuesday has three seats, all taken, and A, B and C are waiting. One passenger cancels, all three
 * are offered the seat, A accepts, B and C are closed `taken_by_other`. Now a second passenger
 * cancels the same Tuesday. The fan-out runs, `insertOrIgnore` finds a row for B and a row for C,
 * ignores both — and **nobody is told**. The car leaves with an empty seat while two women the
 * driver already approved sit waiting for exactly it, and nothing anywhere reports a failure.
 *
 * The retry safety the index was there for is real, but it belongs to the RELEASE: the thing that
 * must not happen twice is one freeing being announced twice, and `release_event_id` already
 * identifies that. So the key moves to `(seat_request_id, release_event_id)` — the same cancellation
 * processed twice still cannot offer her two chances — and "no two LIVE offers for one day" is now
 * an explicit check in the fan-out, where it can be read, instead of a side effect of a key that
 * also forbade the legitimate case.
 *
 * ⚠️ The existing test named "does not offer the same seat to the same person twice" keeps passing,
 * and it is worth knowing why it never caught this: it simulates a retry by calling the fan-out
 * again, which mints a NEW release id. Under the old key that was indistinguishable from a second
 * seat genuinely freeing, so the test proved retry-safety using the very behaviour that broke the
 * real case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_offers', function (Blueprint $table) {
            /*
             * Added BEFORE the unique index is dropped. `scheduled_trip_id` is a foreign key, and
             * MySQL was satisfying it with the unique index's leftmost column — drop that first and
             * the statement is refused ("needed in a foreign key constraint").
             *
             * It also earns its place: "every live offer on this day", which is how a winner closes
             * everybody else's, is exactly this index.
             */
            $table->index(['scheduled_trip_id', 'status'], 'seat_offers_day_status');
        });

        Schema::table('seat_offers', function (Blueprint $table) {
            // 🔴 One offer per person per RELEASE. The retry guard, keyed on what actually must not
            // repeat.
            $table->unique(['seat_request_id', 'release_event_id'], 'seat_offers_one_per_release');
        });

        Schema::table('seat_offers', function (Blueprint $table) {
            $table->dropUnique(['scheduled_trip_id', 'seat_request_id']);
        });
    }

    public function down(): void
    {
        /*
         * ⚠️ The reverse can fail on real data, and that is honest rather than a flaw: rows that
         * are legal under the new key — a woman offered the same day twice, by two different
         * releases — are exactly what the old key forbade. A rollback on a database where that has
         * happened needs a decision about which of her offers to keep, which is not a migration's
         * to make.
         */
        Schema::table('seat_offers', function (Blueprint $table) {
            $table->unique(['scheduled_trip_id', 'seat_request_id']);
        });

        Schema::table('seat_offers', function (Blueprint $table) {
            $table->dropUnique('seat_offers_one_per_release');
            $table->dropIndex('seat_offers_day_status');
        });
    }
};
