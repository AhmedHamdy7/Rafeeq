<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_wait_timers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('trip_session_id')->constrained('trip_sessions')->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->smallInteger('grace_seconds')->default(300); // from platform_settings, not hard-coded
            $table->smallInteger('extended_seconds')->default(0);
            $table->string('outcome', 20)->nullable(); // arrived|no_show|driver_left
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_wait_timers');
    }
};
