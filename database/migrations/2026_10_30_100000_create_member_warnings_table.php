<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two escalating warning counters (Feature 03 v5 §239).
 *
 * > *"Track lateness incident separately: 1st warning, 2nd warning, 3rd fee eligible (amount not
 * > shown until Payments rules). **Avoid confusing with the distinct no-show/late-cancel counter
 * > (first warning, second fee).** Provide clear explanation when warnings displayed, with dispute
 * > link."*
 *
 * 🔴 **One table with a `kind`, because v5's own instruction is that the two must not be confused.**
 * Two tables would let them drift into two shapes, two dispute paths and two ways of counting; one
 * table with the kind in the open makes the distinction a column somebody can read, and the
 * difference that matters — a lateness gets two warnings before a fee, a no-show gets one — lives
 * in one place beside it.
 *
 * 🔴 **And this is NOT the Reliability Score.** They measure the same events and answer different
 * questions, which is why v5 describes them separately. The score is an aggregate inside a window
 * that routes to a monthly review; this is the escalation a person is actually *told* about —
 * "1st warning", then "2nd", then a fee becomes possible. A single mechanism would mean either
 * telling somebody her score every time it moved, or never telling her anything before a fee.
 *
 * 🔒 Disputable, as v5 requires of both counters. A warning is held against somebody, so unwinding
 * it is a reviewer's job rather than the driver's — the same discipline `attendance` already
 * follows for a no-show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_warnings', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * `late_boarding` — she made the car wait and then boarded (v5: `PICKED_UP`, **not**
             * no-show). `missed_commitment` — a no-show, or a cancellation close enough to
             * departure that the driver could not fill the seat.
             */
            $table->string('kind', 30);

            /*
             * The booking it is about. Nullable because a retention sweep may remove a booking long
             * before the warning's own record should go — and a warning whose booking vanished is
             * still a thing somebody was told.
             */
            $table->foreignUlid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();

            /*
             * 🔴 Which warning this was, SNAPSHOTTED — "1st", "2nd", "3rd".
             *
             * Stored rather than derived, and for the same reason `reliability_events.weight` is:
             * it is a record of **what she was told**, not a live count. If an earlier warning is
             * later overturned on dispute, the sentence she actually read stays true about the
             * moment she read it. The live standing is counted from the rows that have not been
             * overturned, which is a different question and is asked separately.
             */
            $table->unsignedSmallInteger('ordinal');

            /*
             * Whether this one crosses into "a fee may follow" — the third lateness, the second
             * missed commitment.
             *
             * ⚠️ A flag and never an amount. v5: "amount not shown until Payments rules", and the
             * cancellation policy is still deferred. Writing a number here would be inventing the
             * one thing v5 is most careful not to state.
             */
            $table->boolean('fee_eligible')->default(false);

            // The local day it happened, which is what the message names: "Sun 18 Oct".
            $table->date('occurred_on');

            /*
             * 🔴 When she was TOLD, which is not when it happened.
             *
             * v5 §10 is emphatic: *"do **not** show any lateness message during the trip. After
             * drop-off the passenger receives a push-style notification."* So a lateness warning is
             * recorded the moment it is known and sits here undelivered until her journey ends —
             * because telling somebody off while she is sitting in the car of the person she kept
             * waiting is a thing a product does only once.
             *
             * A missed commitment has no journey to wait for and is delivered as it is recorded.
             */
            $table->dateTime('delivered_at')->nullable();

            // 🔒 Her side of it. The resolution is a reviewer's, never the driver's.
            $table->dateTime('disputed_at')->nullable();
            $table->string('dispute_reason', 255)->nullable();
            $table->string('dispute_resolution', 30)->nullable();
            $table->foreignUlid('dispute_resolved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->dateTime('dispute_resolved_at')->nullable();

            $table->timestamps();

            /*
             * One warning per person per booking per kind. A drop-off that is retried, or a
             * completion sweep that runs twice over the same run, must not tell somebody she was
             * late twice for one morning.
             */
            $table->unique(['user_id', 'kind', 'booking_id'], 'member_warnings_one_per_booking');

            // "Her warnings, and how many of this kind still stand" — the two reads.
            $table->index(['user_id', 'kind', 'disputed_at'], 'member_warnings_standing');

            // The delivery sweep's read: recorded and not yet told.
            $table->index(['delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_warnings');
    }
};
