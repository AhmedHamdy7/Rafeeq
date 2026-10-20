<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The driver's third answer to a seat request: a different meeting place (Feature 03 v5 §2).
 *
 * > *"The driver must review them and explicitly **Accept**, **Reject**, or **Counteroffer** a
 * > different pickup/drop-off. A counteroffer requires passenger's explicit in-app acceptance; chat
 * > is not consent. Only after confirmation do the passenger's **agreed** pickup/drop-off become
 * > committed route stops."*
 *
 * 🔴 **Why columns rather than reusing `pickup_point_requests`.** That table already carries a
 * counter-offer flow, and it looks like the obvious home — but it answers a different question. It
 * is for somebody who ALREADY has a seat asking to be collected somewhere else, and the driver
 * countering that. This is the driver countering a request that has no seat yet, on either or both
 * points, before anybody has agreed to anything. Routing one through the other would mean a pending
 * request owning a pickup-change request against a booking that does not exist.
 *
 * 🔴 **And `active_request_key` has to change with it.** The generated column collapses to NULL for
 * every status except `pending` and `approved`, which is what enforces "one open request per
 * passenger per commute". A counteroffered request is unquestionably open — the driver has answered
 * and is waiting on the passenger — so leaving it outside the key would let her file a second
 * request on the same commute while the first conversation is still live, and the driver would see
 * two of her.
 *
 * MySQL cannot redefine a generated column's expression in place while a unique index depends on
 * it, so the index and the column are dropped and rebuilt. No data moves: the column is virtual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            foreach (['pickup', 'dropoff'] as $kind) {
                /*
                 * Nullable, and either one alone is a valid counteroffer: a driver may be happy to
                 * collect her where she asked and want to set her down somewhere else. Both null
                 * is not a counteroffer at all, and the Action refuses it.
                 */
                $table->geography("counteroffer_{$kind}_point", subtype: 'point', srid: 4326)->nullable();
                $table->decimal("counteroffer_{$kind}_lat", 10, 7)->nullable();
                $table->decimal("counteroffer_{$kind}_lng", 10, 7)->nullable();
                $table->string("counteroffer_{$kind}_label", 255)->nullable();
            }

            $table->dateTime('counteroffered_at')->nullable();
            $table->foreignUlid('counteroffered_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Why she is proposing somewhere else — "I can't get into that street at 7am".
             *
             * Optional, like every other driver note in this codebase: a driver choosing where to
             * stop her own car does not owe a justification, and demanding one produces boxes
             * filled with "no". But this is the one note worth asking for, because it is the
             * difference between a passenger accepting a strange point and refusing it.
             */
            $table->string('counteroffer_note', 255)->nullable();

            /*
             * Kept after acceptance rather than cleared. The agreed points move onto
             * `requested_*` and then onto the booking's immutable snapshot; these stay as the
             * record of who proposed the place somebody was told to stand in, which is what a
             * dispute about a missed pickup turns on.
             */
            $table->dateTime('counteroffer_answered_at')->nullable();

            /*
             * 🔴 Widened from 20, because `counteroffer_declined` is 21 characters and MySQL
             * truncates rather than warning.
             *
             * Widening the column rather than shortening the value: the status is read by people
             * in support and in the audit trail, and `counter_declined` saves five characters by
             * making the one status whose whole purpose is to say precisely what happened say it
             * less precisely. See {@see \App\Domains\Booking\Enums\SeatRequestStatus} on why that
             * case exists at all.
             */
            $table->string('status', 30)->default('pending')->change();
        });

        // The index first: MySQL will not drop a column a unique index is built on.
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->dropUnique(['active_request_key']);
            $table->dropColumn('active_request_key');
        });

        Schema::table('seat_requests', function (Blueprint $table) {
            $table->string('active_request_key', 80)
                ->nullable()
                ->virtualAs("IF(status IN ('pending','approved','counteroffered'), CONCAT(passenger_user_id, ':', commute_offer_id), NULL)");
            $table->unique('active_request_key');
        });
    }

    public function down(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->dropUnique(['active_request_key']);
            $table->dropColumn('active_request_key');
        });

        Schema::table('seat_requests', function (Blueprint $table) {
            $table->string('active_request_key', 80)
                ->nullable()
                ->virtualAs("IF(status IN ('pending','approved'), CONCAT(passenger_user_id, ':', commute_offer_id), NULL)");
            $table->unique('active_request_key');
        });

        // Any request still counteroffered would be invisible to the restored key, so it is sent
        // back to pending — the state it was in before the driver answered.
        DB::table('seat_requests')->where('status', 'counteroffered')->update(['status' => 'pending']);

        Schema::table('seat_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('counteroffered_by');
            $table->dropColumn([
                'counteroffer_pickup_point', 'counteroffer_pickup_lat', 'counteroffer_pickup_lng', 'counteroffer_pickup_label',
                'counteroffer_dropoff_point', 'counteroffer_dropoff_lat', 'counteroffer_dropoff_lng', 'counteroffer_dropoff_label',
                'counteroffered_at', 'counteroffer_note', 'counteroffer_answered_at',
            ]);
        });
    }
};
