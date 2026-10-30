<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asking for a seat on a run that has already set off (Feature 03 v5 §148, §416).
 *
 * > *"Driver may accept new active-ride requests if feasible; those separate incoming active-ride
 * > requests **expire in 3 minutes**."* And: *"driver sees all incoming requests in one dismissible
 * > bottom sheet ... **[v2]** The 3-minute active-ride request is the **only** timed item left."*
 *
 * 🔴 **A column rather than a table, because this IS a seat request.** v5 calls these "separate",
 * and the separation it describes is a separate SHEET on the driver's screen — not a separate
 * object. Everything a seat request already carries is what one of these needs: the two agreed
 * points and their validation, the driver's approval path, the expiry clock, the counteroffer, the
 * booking it turns into. A second table would have meant a second approval path, and the day they
 * disagreed would be the day somebody was seated by the one with the weaker checks.
 *
 * What the flag buys is the two things that genuinely differ: the client can show them in their own
 * sheet, and the expiry is three minutes rather than the ordinary window — because a request to
 * join a car that is moving is answerable for as long as the car is near her and no longer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            /*
             * Set at creation and never changed. It records the CIRCUMSTANCES the request was made
             * in, not its state — so a request made mid-journey and answered after the run ends is
             * still one of these, which is what a support conversation about it needs to know.
             */
            $table->boolean('requested_during_ride')->default(false)->after('commitment');

            // The driver's sheet: "what is being asked of me on this run, right now".
            $table->index(['scheduled_trip_id', 'requested_during_ride'], 'seat_requests_during_ride');
        });
    }

    public function down(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->dropIndex('seat_requests_during_ride');
            $table->dropColumn('requested_during_ride');
        });
    }
};
