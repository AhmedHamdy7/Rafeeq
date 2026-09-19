<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Declared attendance ("I'm coming tomorrow") — helps the driver plan.
     * Not to be confused with `attendance` (ERD group ⑩), the ACTUAL
     * check-in that drives billing.
     */
    public function up(): void
    {
        Schema::create('group_attendance', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commute_group_id')->constrained('commute_groups')->cascadeOnDelete();
            $table->foreignUlid('scheduled_trip_id')->constrained('scheduled_trips')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20); // coming|away|no_response
            $table->dateTime('marked_at')->nullable();
            $table->timestamps();

            $table->unique(['commute_group_id', 'scheduled_trip_id', 'user_id'], 'group_attendance_unique_declaration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_attendance');
    }
};
