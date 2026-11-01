<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No booking deadline any more (Feature 03 R-5; follow-up N2, partners' decision).
 *
 * Every generated day used to stop taking requests at 21:00 the night before. Nothing in the
 * decided product keeps that: a request never expires (R-4), the driver answers whenever he
 * answers, the 16-hour lock is what protects a booked morning from change, and a car can even be
 * asked for a seat once it is moving. The cut-off only refused people a seat that was still there.
 *
 * Dropped rather than nulled: the ~30 generated days per commute all carried a value, and a column
 * nothing writes is one somebody later reads as a rule. A day stops taking ordinary requests at its
 * departure; after that only the during-ride path applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->dropColumn('booking_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->dateTime('booking_deadline_at')->nullable()->after('seats_total');
        });
    }
};
