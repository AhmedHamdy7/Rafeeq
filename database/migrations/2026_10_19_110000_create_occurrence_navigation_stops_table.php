<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ordered stops the driver actually drives on one day (Feature 03 v5 §5:
 * `occurrence_navigation_stops`).
 *
 * > *"ordered, derived stops with `DRIVER_START|PASSENGER_PICKUP|PASSENGER_DROPOFF|DRIVER_END`,
 * > `booking_id?`, coordinates, ETA, reached/completed flags and itinerary version. Derived order
 * > can interleave passengers."*
 *
 * 🔴 **Derived, but stored — and the reason is the flags.** Every coordinate in here can be
 * recomputed from the commute's route and its confirmed bookings, so a table looks like a cache of
 * something already known. What cannot be recomputed is `reached_at`: that is the driver ticking
 * off a stop on a live run, and it only exists here. A purely computed itinerary would lose her
 * progress every time a booking changed.
 *
 * So the rows are rebuilt when the stops change, and a rebuild **preserves `reached_at`** by
 * matching on booking and kind — see `BuildNavigationItineraryAction`.
 *
 * 🔴 **`sequence` is not `Start → A pickup → A drop-off → B pickup → B drop-off → End`.** v5 warns
 * against assuming that shape: *"shared journeys can interleave pickups and drop-offs"*. The order
 * comes from how far along the driver's own route each point sits, with one invariant imposed on
 * top — a passenger's pickup always precedes her own drop-off.
 *
 * Nothing here is authoritative about what was AGREED. That lives on `bookings`, as an immutable
 * snapshot, precisely so that rebuilding this table can never rewrite what somebody was promised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occurrence_navigation_stops', function (Blueprint $table) {
            $table->ulid('id')->primary();

            /*
             * The occurrence: one generated day. v5 calls it an occurrence; this codebase has
             * always called it a scheduled trip, and renaming the table everybody already reads
             * would be a bigger lie than the vocabulary gap.
             */
            $table->foreignUlid('scheduled_trip_id')->constrained('scheduled_trips')->cascadeOnDelete();

            /*
             * Null for the driver's own two endpoints. `cascadeOnDelete` because a stop without its
             * booking is a stop for nobody — and the agreed snapshot that matters for audit lives
             * on the booking itself, not here.
             */
            $table->foreignUlid('booking_id')->nullable()->constrained('bookings')->cascadeOnDelete();

            $table->string('kind', 20); // driver_start|passenger_pickup|passenger_dropoff|driver_end
            $table->unsignedSmallInteger('sequence');

            $table->geography('point', subtype: 'point', srid: 4326);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('label', 255)->nullable();

            /*
             * When the driver is expected here, computed by routing each leg in order.
             *
             * Nullable, and honestly so: v5 says "Do not create fake distance/duration/ETA values",
             * so a day whose legs could not be routed carries no ETA rather than an interpolated
             * guess that would read exactly like a real one.
             */
            $table->dateTime('eta_at')->nullable();

            // How long the leg INTO this stop takes, and how far. Kept per stop so a client can
            // show "12 min to the next pickup" without re-deriving it from the ETAs.
            $table->unsignedInteger('leg_duration_seconds')->nullable();
            $table->unsignedInteger('leg_distance_meters')->nullable();

            /*
             * 🔴 The one column that is not derived. Set when the driver reaches the stop, and
             * carried across every rebuild — v5's "reached/completed flags".
             */
            $table->dateTime('reached_at')->nullable();

            // Which build produced this row, so a stale read is detectable rather than silent.
            $table->unsignedInteger('itinerary_version');

            $table->timestamps();

            // One stop per position per day: a duplicate sequence would make "the next stop"
            // ambiguous on a screen somebody is driving by.
            $table->unique(['scheduled_trip_id', 'sequence']);
            $table->index(['scheduled_trip_id', 'kind']);
            $table->index('booking_id');
        });

        Schema::table('scheduled_trips', function (Blueprint $table) {
            /*
             * Bumped by anything that changes which stops exist — a booking confirmed, a booking
             * cancelled, an agreed pickup moved. Bumping is one cheap UPDATE with no provider call,
             * which is what lets it happen inside the transaction that holds the seat lock.
             */
            $table->unsignedInteger('itinerary_version')->default(0)->after('seats_taken');

            /*
             * Which version the stored stops were built from. When it trails `itinerary_version`
             * the itinerary is stale and is rebuilt on the next read.
             *
             * 🔴 Two columns rather than one, because building needs a routing provider and
             * bumping does not. Rebuilding inside the transaction that confirms a seat would put a
             * third party's latency inside a row lock on the seat count — the busiest lock in the
             * system. So the write side bumps, and the read side builds.
             */
            $table->unsignedInteger('itinerary_built_version')->default(0)->after('itinerary_version');
            $table->dateTime('itinerary_built_at')->nullable()->after('itinerary_built_version');

            // The whole run as one line, for the map, with its totals summed from the legs.
            $table->text('itinerary_polyline')->nullable()->after('itinerary_built_at');
            $table->unsignedInteger('itinerary_distance_meters')->nullable()->after('itinerary_polyline');
            $table->unsignedInteger('itinerary_duration_seconds')->nullable()->after('itinerary_distance_meters');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->dropColumn([
                'itinerary_version', 'itinerary_built_version', 'itinerary_built_at',
                'itinerary_polyline', 'itinerary_distance_meters', 'itinerary_duration_seconds',
            ]);
        });

        Schema::dropIfExists('occurrence_navigation_stops');
    }
};
