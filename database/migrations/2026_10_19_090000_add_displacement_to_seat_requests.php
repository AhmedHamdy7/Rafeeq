<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a waiting-list membership that the platform created by taking somebody's seat away
 * (Feature 03 v5: "displaced passengers are notified and automatically added to preserved waiting
 * list").
 *
 * 🔴 Why two columns rather than none. A displaced passenger's request is flipped from `approved`
 * back to `waitlisted`, in place — that is what "preserved waiting list" means, and reusing the row
 * keeps her approval, her terms and her intro message exactly as the driver accepted them. But
 * without a marker, that row is indistinguishable from somebody who has only ever queued, and the
 * two are not the same person to a driver choosing who to offer a seat to on resume. v5 asks for a
 * **Previous Passenger** label on those cards; this is what makes one truthful.
 *
 * `displaced_reason` is kept beside the timestamp because "she paused the commute" and "she changed
 * her car" are different news, and a card that says only "displaced" invites the driver to guess.
 *
 * Nothing is backfilled. Before this migration no row was ever displaced — the behaviour it records
 * is introduced in the same change — so every existing row's honest value is null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            // Null for everybody who joined the list by asking. Set only by displacement, and
            // deliberately NOT cleared when she wins a seat again: that she was once displaced is
            // part of the history of the commute, and the status column already says where she is.
            $table->dateTime('displaced_at')->nullable()->after('waitlist_position');
            $table->string('displaced_reason', 30)->nullable()->after('displaced_at');
        });
    }

    public function down(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->dropColumn(['displaced_at', 'displaced_reason']);
        });
    }
};
