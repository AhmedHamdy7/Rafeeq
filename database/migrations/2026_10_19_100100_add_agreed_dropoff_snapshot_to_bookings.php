<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A booking records the pickup AND drop-off that were actually agreed (Feature 03 v5 §5:
 * `bookings`: *"immutable agreed pickup/drop-off snapshots"*).
 *
 * 🔴 The pickup half already existed as `pickup_point`, but almost nothing filled it: it was
 * written only when a separate custom-pickup-point request was approved, so for an ordinary booking
 * it was null — and the attendance geofence, the home screen's next-journey card and the booking
 * payload all read it. From this change on, `CreateBookingAction` snapshots the pickup the passenger
 * chose, so those three finally have a point to work with for every booking. This migration adds
 * the label beside it and the drop-off that had no column at all.
 *
 * **Why these stay nullable in the database when `seat_requests` made the same fields NOT NULL.**
 * The snapshot is written by one Action, from a source where the columns are already NOT NULL, so
 * the invariant holds where the data is created. What the database must still tolerate is a booking
 * whose seat request row was deleted — `bookings.seat_request_id` is `nullOnDelete`, which is the
 * deliberate choice that a person's booking history survives the deletion of the request that
 * created it. Making these NOT NULL would mean inventing coordinates for such a row, and a point
 * somebody is told to stand at is the last thing to invent.
 *
 * Existing bookings are backfilled from their seat request, which the previous migration guaranteed
 * has both points.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // The readable name of the agreed pickup, beside the point that was already here.
            $table->string('pickup_label', 255)->nullable()->after('pickup_point');
            $table->decimal('pickup_lat', 10, 7)->nullable()->after('pickup_label');
            $table->decimal('pickup_lng', 10, 7)->nullable()->after('pickup_lat');

            $table->geography('dropoff_point', subtype: 'point', srid: 4326)->nullable()->after('pickup_lng');
            $table->string('dropoff_label', 255)->nullable()->after('dropoff_point');
            $table->decimal('dropoff_lat', 10, 7)->nullable()->after('dropoff_label');
            $table->decimal('dropoff_lng', 10, 7)->nullable()->after('dropoff_lat');
        });

        /*
         * Copied from the seat request, in one statement per side.
         *
         * 🔴 `pickup_point` is only overwritten where it is NULL. A booking that already has one
         * got it from an approved custom-pickup-point request, which is a LATER agreement than the
         * original seat request — overwriting it would move a meeting point that two people settled
         * on back to what she first asked for.
         */
        DB::statement(<<<'SQL'
            UPDATE bookings b
            JOIN seat_requests r ON r.id = b.seat_request_id
            SET b.pickup_point = COALESCE(b.pickup_point, r.requested_pickup_point),
                b.pickup_label = r.requested_pickup_label,
                b.pickup_lat   = r.requested_pickup_lat,
                b.pickup_lng   = r.requested_pickup_lng,
                b.dropoff_point = r.requested_dropoff_point,
                b.dropoff_label = r.requested_dropoff_label,
                b.dropoff_lat   = r.requested_dropoff_lat,
                b.dropoff_lng   = r.requested_dropoff_lng
            WHERE b.dropoff_point IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'pickup_label', 'pickup_lat', 'pickup_lng',
                'dropoff_point', 'dropoff_label', 'dropoff_lat', 'dropoff_lng',
            ]);
        });
    }
};
