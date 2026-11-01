<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The early-drop check (TRIP-20, PAY-28; Feature 03 backend file F-9).
 *
 * When the driver sets her down more than 1 km before her agreed drop-off and she had not asked
 * ("Drop me here"), she is asked "Did you ask to get off here?". The question, how far short it was,
 * and her answer live on the booking: `yes` → the full fare stands; `no` → she pays nothing, he pays
 * the whole seat, and a report opens for GPS review. No answer is open question Q-066 and does
 * nothing yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dateTime('early_drop_asked_at')->nullable()->after('fee_line_reason');
            $table->unsignedInteger('early_drop_distance_meters')->nullable()->after('early_drop_asked_at');
            $table->string('early_drop_answer', 5)->nullable()->after('early_drop_distance_meters'); // yes|no
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['early_drop_asked_at', 'early_drop_distance_meters', 'early_drop_answer']);
        });
    }
};
