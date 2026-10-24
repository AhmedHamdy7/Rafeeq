<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `Receive Requests During Ride` (Feature 03 v5 §8, §9 and its API table:
 * *"`PATCH /driver/commutes/{id}/receive-requests` — Default true; per occurrence"*).
 *
 * ⚠️ **Nothing reads this column yet, and that is deliberate rather than an oversight.** The
 * active-ride request it governs — v5's *"separate incoming active-ride requests expire in 3
 * minutes"* — is not built. Building it would mean inventing the piece v5 leaves open: *"on active
 * trip, reachability depends on actual route progress and predicted ETA"*, which is a model nothing
 * here has. v5's own out-of-scope note asks for exactly this instead: *"implement durable
 * states/hooks, not speculative behavior."*
 *
 * 🔴 So why store it at all, when an event type nothing emits was deliberately NOT declared
 * elsewhere in this codebase? Because the two cases fail in opposite directions. An event nothing
 * publishes tells a client to wait for something that never arrives — it promises. This promises
 * nothing: it records a driver's answer to "may I be asked mid-journey?", and the default is YES, so
 * every driver's stored state is already the behaviour she would get. The only answer that could be
 * broken is "no", and it is impossible to break while nothing asks her — whereas if her "no" were
 * only collected the day the feature ships, the first mid-journey request would reach a driver who
 * had already refused them in a previous version of the app.
 *
 * **Per occurrence, as v5 says.** Not per commute: whether she can take another passenger depends on
 * this morning — who is already aboard, how late she is, where she has got to — and a commute-wide
 * switch would make her answer for a quiet Thursday on the strength of a bad Tuesday.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            /*
             * Default true, which v5 states outright. A driver who has never opened the toggle is
             * reachable, because the feature exists to fill an empty seat on a journey that is
             * already happening — and defaulting to "no" would turn the whole thing off for
             * everybody who never found the switch.
             */
            $table->boolean('receives_requests_during_ride')
                ->default(true)
                ->after('booking_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->dropColumn('receives_requests_during_ride');
        });
    }
};
