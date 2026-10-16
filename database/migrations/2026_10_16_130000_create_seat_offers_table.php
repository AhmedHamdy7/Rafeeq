<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One fan-out of a freed seat to everybody on the waiting list (Feature 03 v3: "Seat opens →
 * automatic offer to all currently available eligible waiting-list members; **first eligible
 * server-committed acceptance wins**, no 5-minute timer or driver manual selection").
 *
 * 🔴 This reverses a decision the codebase argued for in writing, so the argument deserves an
 * answer rather than a silent overwrite.
 *
 * `PromoteFromWaitlistAction` promoted exactly one person per freed seat, and said why: "promoting
 * the whole queue would turn a single cancellation into five hopeful people competing for one seat,
 * and four of them finding out by being refused." That is a real cost and it still is. What changes
 * it is WHEN the refusal happens and what it costs the person:
 *
 *   - under promotion, the winner was moved to `pending` and still needed the driver to approve
 *     her. So a freed seat could sit unfilled for up to 48 hours waiting on one person's reply,
 *     while four others waited behind her, and she could still be refused at the end of it.
 *   - under fan-out, everybody on the list is already driver-approved — she approved them when she
 *     put them on the waiting list — so the first acceptance IS the seat. Nobody is "refused":
 *     the others see `taken_by_other`, which is the truth and is what a waiting list means.
 *
 * So the driver still decides who rides with her; she decides it earlier. That rule is untouched.
 *
 * ⚠️ Product decision, 2026-10-16 — the HYBRID: `waitlist_position` stays and is still shown, but
 * as an **estimate**, not a promise of order. The design's "Position 1 of 2 · Est. seat within ~1
 * week" is kept as a forecast while the seat itself is a race. The one thing a client must never do
 * is tell somebody her position guarantees her the next seat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_offers', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // The specific day whose seat came free. An offer is always for one journey, even when
            // the waiting request behind it is recurring.
            $table->foreignUlid('scheduled_trip_id')->constrained('scheduled_trips')->cascadeOnDelete();

            // Who is being offered it — the waiting request, which carries her pickup, her seats
            // and the driver's earlier approval.
            $table->foreignUlid('seat_request_id')->constrained('seat_requests')->cascadeOnDelete();

            /*
             * open | accepted | taken_by_other | ineligible | closed
             *
             * `taken_by_other` and `closed` are deliberately different: the first means somebody
             * won the race, the second that the seat stopped existing (the trip was cancelled, or
             * the driver filled it herself). A person reading her own history should be able to
             * tell "somebody was quicker" from "it evaporated".
             */
            $table->string('status', 20)->default('open');

            /*
             * Which release this offer belongs to, so one cancellation's fan-out can be closed as a
             * set and counted as one event. Not a foreign key: the releasing booking may be deleted
             * by a retention sweep long before these rows are, and losing the grouping is worse
             * than losing referential integrity on a label.
             */
            $table->ulid('release_event_id');

            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            /*
             * 🔴 One open offer per person per journey. This is what makes the race safe to retry:
             * a duplicate fan-out — the same cancellation processed twice — cannot give one person
             * two chances at the same seat, and cannot create two acceptances to reconcile.
             */
            $table->unique(['scheduled_trip_id', 'seat_request_id']);

            // "What am I being offered right now" and "close this whole fan-out" are the two reads.
            $table->index(['seat_request_id', 'status']);
            $table->index(['release_event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_offers');
    }
};
