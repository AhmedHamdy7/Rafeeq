<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // RESTRICT (default): 7-year financial retention per ERD §18 —
            // never cascades away when a trip/user row changes.
            $table->foreignUlid('scheduled_trip_id')->constrained('scheduled_trips');
            $table->foreignUlid('passenger_user_id')->constrained('users');
            $table->foreignUlid('driver_profile_id')->constrained('driver_profiles', 'user_id'); // denormalized for fast driver queries
            $table->foreignUlid('commute_group_id')->nullable()->constrained('commute_groups')->nullOnDelete();
            $table->foreignUlid('seat_request_id')->nullable()->constrained('seat_requests')->nullOnDelete();
            $table->unsignedTinyInteger('seats_reserved')->default(1);
            // 🔒 Frozen forever at approval time — the offer's price/fee %
            // can change tomorrow without touching bookings made today.
            $table->unsignedInteger('price_snapshot_piastres');
            $table->unsignedInteger('platform_fee_snapshot_piastres');
            $table->unsignedInteger('driver_amount_snapshot_piastres');
            $table->string('payment_type', 10); // cash | online
            $table->string('payment_status', 20)->default('not_due'); // not_due|pending|paid|failed|refunded|disputed
            $table->string('status', 30)->default('pending'); // pending|confirmed|completed|cancelled_by_passenger|cancelled_by_driver|expired|no_show
            $table->foreignUlid('pickup_place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->geography('pickup_point', subtype: 'point', srid: 4326)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_reason', 255)->nullable();
            $table->unsignedInteger('cancellation_fee_piastres')->default(0);
            $table->timestamps();

            // 🔴 ERD §20 constraint #7 — the second line of defense against a
            // double booking (lockForUpdate on the trip is the first, Phase 7).
            $table->unique(['scheduled_trip_id', 'passenger_user_id']);
            $table->index(['passenger_user_id', 'status', 'created_at']);
            $table->index(['driver_profile_id', 'status']);
            $table->index(['commute_group_id', 'status']);
        });

        // ERD §20 constraint #16 (checked here for bookings' own frozen
        // snapshot; the live payments-table version is enforced in group ⑨).
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT chk_bookings_amount_split '.
            'CHECK (price_snapshot_piastres = platform_fee_snapshot_piastres + driver_amount_snapshot_piastres)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
