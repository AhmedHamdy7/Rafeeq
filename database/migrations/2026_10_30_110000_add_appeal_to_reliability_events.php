<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filing an appeal against a reliability event (Feature 03 v5 §115).
 *
 * > *"Admin monthly reviews low Reliability/repeat violations for drivers and passengers;
 * > warning/contact, temporary suspension, permanent person/account/phone ban **with appeal**."*
 *
 * 🔴 The OUTCOME of an appeal has been storable since the ledger was built — `reversed_at`,
 * `reversed_by`, `reversed_reason` — and nothing could ever write them, because there was no way to
 * file one. An appeal that cannot be filed is not an appeal, and a score that can end an account
 * with no way to answer it is the thing v5 attaches the word to.
 *
 * So the asking and the answer are two sets of columns on purpose:
 *
 *   - `appealed_at` / `appeal_reason` — hers. Written once, by her.
 *   - `reversed_*` — the reviewer's. Written once, by somebody else.
 *
 * ⚠️ **Filing changes nothing on its own.** The event keeps counting until a reviewer reverses it,
 * for the same reason a disputed warning keeps counting: if asking were enough to pause the
 * consequence, every event would be appealed as a matter of course and the score would never mean
 * anything. What filing buys her is that somebody has to look.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reliability_events', function (Blueprint $table) {
            $table->dateTime('appealed_at')->nullable()->after('commute_offer_id');

            /*
             * Her account of the morning. Required when filing, and it is the whole case: there is
             * no evidence attached to a reliability event for a reviewer to weigh, so what she says
             * happened is all there is.
             */
            $table->string('appeal_reason', 255)->nullable()->after('appealed_at');

            /*
             * Set when a reviewer declines it. An approved appeal is recorded by `reversed_at`
             * instead — the two outcomes are stored differently because only one of them changes
             * what the score says, and a reviewer reading the row needs to see which happened
             * without inferring it from an absence.
             */
            $table->dateTime('appeal_declined_at')->nullable()->after('appeal_reason');

            // The queue's only read: filed, and not yet answered either way.
            $table->index(['appealed_at', 'reversed_at', 'appeal_declined_at'], 'reliability_events_open_appeals');
        });
    }

    public function down(): void
    {
        Schema::table('reliability_events', function (Blueprint $table) {
            $table->dropIndex('reliability_events_open_appeals');
            $table->dropColumn(['appealed_at', 'appeal_reason', 'appeal_declined_at']);
        });
    }
};
