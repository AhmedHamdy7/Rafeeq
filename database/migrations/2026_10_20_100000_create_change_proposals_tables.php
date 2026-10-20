<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A driver's proposed change to a commute people have already booked, and each affected
 * passenger's answer (Feature 03 v5 §6).
 *
 * > *"Route/time/pickup changes affecting confirmed passengers require unanimous explicit
 * > approval, revisions reset approvals; no response means no change."*
 *
 * 🔴 **This ADDS a capability rather than restricting one, and that is worth stating.** Until now a
 * published commute's departure and route could not be changed at all —
 * `CommuteState::assertEditable()` allows those only on a DRAFT — so the "16-hour lock" on them was
 * already stricter than v5 asks. v5's post-lock change-by-unanimous-approval presupposes the edit
 * is possible; these two tables are what make it possible, and they make it possible only with
 * every affected passenger's explicit yes.
 *
 * 🔴 **`change_approvals` holds answers, never placeholders.** There is no row meaning "has not
 * replied". v5 is explicit that *"If no reply, nothing changes"*, and unanimity is therefore
 * computed as "every currently-confirmed affected booking has an `accepted` row for the current
 * version". That shape gives v5's harder rule for free: *"New affected passengers confirmed while
 * proposal pending must also approve"* — a seat confirmed after the proposal went out simply has no
 * row, so unanimity is no longer met, with nothing to remember to hook into.
 *
 * 🔴 **A revision bumps `version` instead of deleting answers.** v5 says a revision resets all
 * approvals, and deleting them would do that — but it would also erase that somebody had agreed to
 * the earlier version, which is exactly what the driver's screen wants to show ("she accepted, then
 * I revised"). Keying each answer to the version it answered resets the count and keeps the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_proposals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commute_offer_id')->constrained('commute_offers')->cascadeOnDelete();

            /*
             * The day the proposal is about. Always set, even for the all-future scope: it is the
             * occurrence the driver was looking at, it decides which weekday the change applies to,
             * and its departure is when the proposal expires.
             */
            $table->foreignUlid('scheduled_trip_id')->constrained('scheduled_trips')->cascadeOnDelete();

            $table->string('scope', 30); // only_this_occurrence | all_future_on_this_weekday

            /*
             * What is being proposed, as submitted: `departureTime`, `promisedArrivalTime`,
             * `pickupPoints`. JSON rather than columns because v5 combines several fields in one
             * request and the set will grow — and because an unapplied proposal is a document to be
             * shown side by side with the current terms, not a shadow copy of the schema.
             */
            $table->json('changes');

            /*
             * What those fields were when the proposal was made, so the passenger's screen can show
             * old against new without re-deriving it — and so the comparison she agreed to survives
             * the driver changing something else in the meantime.
             */
            $table->json('previous');

            $table->string('status', 20)->default('pending');

            /*
             * Bumped by every revision. An answer carries the version it answered, so a revision
             * resets the count without erasing who had agreed to what.
             */
            $table->unsignedInteger('version')->default(1);

            // From when the all-future scope takes effect. Null for a single occurrence.
            $table->date('effective_from')->nullable();

            /*
             * When it stops being answerable: the affected occurrence's departure, or the earliest
             * one for a future scope. v5: "Proposal expires at the original locked departure time."
             */
            $table->dateTime('expires_at');

            $table->dateTime('applied_at')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['commute_offer_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('change_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('change_proposal_id')->constrained('change_proposals')->cascadeOnDelete();

            /*
             * The booking that is affected, not the person: somebody riding Tuesday and Thursday is
             * affected twice by a Tuesday-and-Thursday proposal, and has one morning's worth of
             * standing to answer about each.
             */
            $table->foreignUlid('booking_id')->constrained('bookings')->cascadeOnDelete();

            $table->string('response', 20); // accepted | rejected | withdrawn

            // Which version of the proposal she was answering. An answer to an older version does
            // not count towards the current one.
            $table->unsignedInteger('proposal_version');

            $table->dateTime('responded_at');
            $table->timestamps();

            /*
             * One standing answer per booking per version. She may change her mind — v5 lets her
             * withdraw an acceptance until it is applied — and the index makes that an update of
             * her answer rather than a second, contradictory one.
             */
            /*
             * Named explicitly: the three column names together exceed MySQL's 64-character limit
             * for an identifier, so the generated name is rejected outright.
             */
            $table->unique(
                ['change_proposal_id', 'booking_id', 'proposal_version'],
                'change_approvals_one_answer_per_version',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_approvals');
        Schema::dropIfExists('change_proposals');
    }
};
