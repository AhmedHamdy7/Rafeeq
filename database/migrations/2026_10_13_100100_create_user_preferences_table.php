<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-device UI state (Feature 01 v3: "UI may cache mode locally but backend retains
 * cross-device preference if desired").
 *
 * One row per account, so `user_id` IS the primary key — the documented exception to ULID keys for
 * genuine 1:1 tables. A separate id would permit two preference rows for one person, which is a
 * state no code could sensibly resolve.
 *
 * None of this is authorization. `last_active_mode` says which home screen to open, not what the
 * person may do — that is `user_capabilities`, checked per request. A client that tampers with its
 * mode gets a different screen and exactly no extra permission.
 *
 * 🔴 `language` needs care, because the guide already documents `Accept-Language` on every
 * request. These are not rivals and must not be "unified" later by somebody tidying up:
 *
 *   - the HEADER wins for any response to a request, because it is what the app is rendering in
 *     right now
 *   - this COLUMN is for server-initiated text — a push notification has no inbound request and
 *     therefore no header to read
 *
 * Getting that backwards sends somebody push notifications in a language they switched away from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->foreignUlid('user_id')->primary()->constrained('users')->cascadeOnDelete();

            // Which home to open. Null until the person has a capability to be in.
            $table->string('last_active_mode', 20)->nullable(); // passenger | driver

            // Nullable on purpose: null means "never seen", which is what decides whether the
            // walkthrough runs. A boolean default of false would lose the date, and the date is
            // what tells support whether somebody saw the safety explanation before a trip.
            $table->dateTime('onboarding_seen_at')->nullable();
            $table->dateTime('driver_walkthrough_seen_at')->nullable();

            $table->string('language', 5)->nullable(); // ar | en — push only, see the docblock
            $table->string('theme', 20)->nullable();   // light | dark | system

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
