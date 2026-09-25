<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔴 ERD §20 constraint #7 — "one booking per passenger per trip" — narrowed to
 * bookings that still stand.
 *
 * The bug this fixes was reachable from the moment bookings existed, and it produced a
 * 500 rather than a refusal: a passenger cancels their seat on Tuesday, asks the driver
 * again, and the driver's approval hits
 * `bookings_scheduled_trip_id_passenger_user_id_unique` on a row that was cancelled
 * days ago. The application already intends to allow this —
 * `SeatAvailabilityChecker::assertNoDuplicateBooking()` counts only pending, confirmed
 * and completed, with the comment "someone who cancelled and changed their mind should
 * be able to rebook" — so the code and the schema disagreed, and the schema won in the
 * ugliest available way.
 *
 * The constraint's intent is untouched: nobody may hold two live seats on one day.
 * What changes is that a cancelled booking stops being counted as one, which is what
 * the product says it is.
 *
 * Implemented as a generated column collapsing to NULL outside the live statuses —
 * MySQL indexes ignore NULLs for uniqueness, and there is no fluent partial-index
 * helper. The same technique guards `users.phone_e164_active`,
 * `vehicles.active_vehicle_key` and `seat_requests.active_request_key`.
 *
 * ⚠️ The status list here MUST match `assertNoDuplicateBooking()`. They are two
 * statements of one rule, and the whole failure above was them drifting apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            /*
             * The replacement index goes in FIRST, and the order is not cosmetic: the
             * foreign key on `scheduled_trip_id` uses this unique index as its
             * supporting one, so MariaDB refuses the drop outright ("needed in a
             * foreign key constraint") until another index leads with that column.
             *
             * It is wanted anyway — looking up one passenger's booking on one day is
             * what `assertNoDuplicateBooking()` does on every single approval.
             */
            $table->index(['scheduled_trip_id', 'passenger_user_id'], 'bookings_trip_passenger_index');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_scheduled_trip_id_passenger_user_id_unique');

            $table->string('live_booking_key', 80)
                ->nullable()
                ->virtualAs(
                    "IF(status IN ('pending','confirmed','completed'), ".
                    "CONCAT(scheduled_trip_id, ':', passenger_user_id), NULL)"
                );

            $table->unique('live_booking_key');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['live_booking_key']);
            $table->dropColumn('live_booking_key');

            // Restored before the plain index goes, for the same foreign-key reason
            // the two steps above are split.
            $table->unique(['scheduled_trip_id', 'passenger_user_id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_trip_passenger_index');
        });
    }
};
