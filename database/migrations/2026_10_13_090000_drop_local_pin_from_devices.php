<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔴 The local PIN leaves the MVP (Feature 01 v3 §10, and it is gone from the new design).
 *
 * The column only ever held a FACT — "this installation has a PIN" — never a value or a hash, which
 * is why dropping it loses nothing and exposes nothing. What it drove was the launch router: a
 * device without a PIN was sent to set one before anything else, which is where `CREATE_PIN` and
 * `LOCAL_SECURITY_SETUP_OR_HOME` came from. Both go with it.
 *
 * Session restore becomes what the new design asks for: a valid refresh token silently restores, an
 * expired or revoked one returns to Phone/OTP, and nothing is asked of the person in between.
 *
 * `biometric_enabled` is deliberately LEFT. v3 says biometrics must not be mandatory, which is not
 * the same as saying the device may not record a preference — and with no unlock screen left it
 * drives nothing, so dropping it would be a schema change for no behavioural gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('has_local_pin');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Restored as it was: a fact, defaulting to false, never a PIN value.
            $table->boolean('has_local_pin')->default(false)->after('is_trusted');
        });
    }
};
