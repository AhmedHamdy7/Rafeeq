<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ACTUAL check-in that drives billing (decision D18: the driver
     * confirms, not the passenger) — not to be confused with
     * `group_attendance` (declared/planned attendance, ERD group ⑨).
     */
    public function up(): void
    {
        Schema::create('attendance', function (Blueprint $table) {
            // 1:1 with bookings — the FK is the primary key.
            $table->foreignUlid('booking_id')->primary()->constrained('bookings')->cascadeOnDelete();
            $table->string('status', 30)->default('pending'); // pending|present|late|passenger_no_show|driver_no_show|cancelled
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->string('confirmed_by', 20)->nullable(); // 'driver' per decision D18
            $table->foreignUlid('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->boolean('gps_corroborated')->default(false); // supporting evidence only, never proof
            $table->decimal('gps_confidence', 4, 2)->nullable();
            $table->dateTime('disputed_at')->nullable(); // 24h window
            $table->string('dispute_reason', 255)->nullable();
            $table->string('dispute_resolution', 30)->nullable(); // upheld|overturned|refunded
            $table->foreignUlid('dispute_resolved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};
