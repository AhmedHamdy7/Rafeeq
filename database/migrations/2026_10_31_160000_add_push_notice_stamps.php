<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Already told" stamps for the pushes Feature 03 files §8 found missing.
 *
 * 🔴 **A column per message, the same way the delay ladder does it** (`delay_*_at` on
 * `scheduled_trips`). The sweeps run every minute, so "already sent" has to be a fact rather than a
 * calculation — and calculating it from the inbox would break in ninety days, when retention prunes
 * the row and the sweep tells everybody again.
 *
 * - `scheduled_trips.trip_reminder_*_sent_at` — the 1-hour and 10-minute reminders, once per day.
 * - `attendance.heading_to_you_notified_at` — "your driver is heading to you", once per passenger.
 * - `attendance.driver_arrived_notified_at` — "your driver has arrived", once per passenger,
 *   whichever arrival path said it (DriverArrivalNotice).
 * - `user_verifications.expiry_warned_for` — WHICH expiry date the "licence expiring" push was
 *   sent about. A date rather than a flag, so a renewed licence with a new expiry is warned about
 *   again when its own time comes, and the old one is never warned about twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->dateTime('trip_reminder_early_sent_at')->nullable();
            $table->dateTime('trip_reminder_final_sent_at')->nullable();
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->dateTime('heading_to_you_notified_at')->nullable();
            $table->dateTime('driver_arrived_notified_at')->nullable();
        });

        Schema::table('user_verifications', function (Blueprint $table) {
            $table->dateTime('expiry_warned_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_verifications', function (Blueprint $table) {
            $table->dropColumn('expiry_warned_for');
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn(['heading_to_you_notified_at', 'driver_arrived_notified_at']);
        });

        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->dropColumn(['trip_reminder_early_sent_at', 'trip_reminder_final_sent_at']);
        });
    }
};
