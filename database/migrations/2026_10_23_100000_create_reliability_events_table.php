<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger behind the Reliability Score (Feature 03 v5's `reliability_events`).
 *
 * > *"Cancellation/displacement reduces Reliability Score even when fee paid."* · *"Admin monthly
 * > reviews low Reliability/repeat violations for drivers and passengers ... Score/history persist
 * > after reinstatement."* · *"Claimed breakdown/accident does not automatically waive fee or
 * > Reliability impact. Driver may appeal with evidence; approved appeal reverses relevant
 * > fee/score impact."*
 *
 * 🔴 **A ledger of events, never a running total on a profile.** The score is derived from these
 * rows on every read. `RecomputeRatingStatsAction` already states the reason and it applies harder
 * here: *"a figure maintained incrementally drifts the first time a job runs twice, and the wrong
 * value then sticks with nothing to compare it against"*. This number can end somebody's account —
 * v5 routes low reliability to suspension and to a permanent ban — so it has to be reconstructible
 * from what actually happened, line by line, in front of whoever is making that decision.
 *
 * 🔴 **`reversed_at`, never a delete.** v5 lets an approved appeal reverse the impact. Deleting the
 * row would also delete the fact that the event happened and that she appealed it successfully,
 * which is the part a second appeal and a reinstatement both turn on. A reversed event stops
 * counting and stays readable.
 *
 * 🔴 **The weight is snapshotted.** The points each kind costs live in config and are a placeholder
 * pending a product decision. Reading them at score time would mean a policy change silently
 * rewriting what everybody's record already said — including records that had already been
 * reviewed and acted on.
 *
 * ⚠️ Rows are **permanent**; the SCORE looks at a trailing window. v5 says history persists after
 * reinstatement, and a cancellation from two years ago should not still be held against somebody.
 * Those are different questions and the two columns answer them separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reliability_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();

            // Which side of the car this is about. A driver's cancellations never reach her
            // passenger standing — see ReliabilityRole.
            $table->string('role', 20);
            $table->string('kind', 40);

            /*
             * What it cost, as the policy stood when it happened. Not re-read at score time: a
             * weight change must not rewrite a record that has already been reviewed.
             */
            $table->unsignedSmallInteger('weight');

            /*
             * When the thing happened, which is not always when the row was written — a sweep can
             * record a no-show after the fact. The score's window is measured from this.
             */
            $table->dateTime('occurred_at');

            /*
             * What it was about. All nullable because the kinds reach different depths: a
             * displacement is about one booking, a cancelled day is about a trip, and a future kind
             * may be about neither.
             *
             * `nullOnDelete` throughout: a reliability record must outlive the booking it came
             * from. Deleting somebody's history by tidying up a trip would be the opposite of
             * "history persists".
             */
            $table->foreignUlid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignUlid('scheduled_trip_id')->nullable()->constrained('scheduled_trips')->nullOnDelete();
            $table->foreignUlid('commute_offer_id')->nullable()->constrained('commute_offers')->nullOnDelete();

            /*
             * An approved appeal (v5). Reversed, not deleted: the event happened, and that she
             * appealed it successfully is exactly what a second appeal and a reinstatement turn on.
             */
            $table->dateTime('reversed_at')->nullable();
            $table->foreignUlid('reversed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('reversed_reason', 255)->nullable();

            $table->timestamps();

            /*
             * The one query that matters: this person's live events in this role since a date.
             * `reversed_at` is in the index because every score read filters on it.
             */
            $table->index(['user_id', 'role', 'reversed_at', 'occurred_at'], 'reliability_events_score_lookup');
            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reliability_events');
    }
};
