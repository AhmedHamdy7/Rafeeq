<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short code the passenger checks against the car before she gets into it.
 *
 * The design shows it on the active-trip screen, directly beneath the driver and the vehicle:
 *
 *     Nour · Kia Sportage · Silver
 *     Plate ﺱ 4821 · PIN 7391
 *
 * 🔴 **This is NOT the attendance mechanism, and that distinction is load-bearing.**
 *
 * Decision D18 chose the driver's tap to decide who was in the car, and the Master Plan explicitly
 * considered and REJECTED a code for that job: "a QR code fails when a phone is flat, GPS fails in
 * a basement car park, and a PIN fails when somebody is running for the car." That reasoning still
 * stands, and {@see ConfirmAttendanceAction} is still the only thing that decides attendance or
 * money.
 *
 * This code answers a different question. D18 asks "did she board?"; this asks "is this the right
 * car?" — a safety check the passenger performs before getting in, at a kerb, next to a plate she
 * is already comparing. Nothing is charged, refused or recorded on the strength of it.
 *
 * ⚠️ So: never gate `check-in`, `no-show` or any payment on this column. If it ever becomes
 * mandatory, the failure mode is a woman standing next to a car she has verified by plate and
 * sight, locked out of her own commute by a flat battery.
 *
 * One code per booking, and a booking is one day — recurring membership generates a booking per
 * trip — so the code rotates daily without any extra machinery. Four digits because it is read
 * aloud through a car window, not typed into a login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            /*
             * Nullable, and stays null for every booking already in flight. Backfilling would mint
             * codes for journeys that have already happened, which is noise in a safety field.
             * The clients treat null as "no code to show", which is also what an older app does.
             */
            $table->string('boarding_code', 8)->nullable()->after('seat_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('boarding_code');
        });
    }
};
