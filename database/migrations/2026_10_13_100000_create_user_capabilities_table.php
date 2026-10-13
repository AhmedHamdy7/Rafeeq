<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one account is enrolled to do (Feature 01 v3: "One account can have Passenger and Driver
 * capabilities; mode is a UI context, not another login").
 *
 * 🔴 This does NOT replace `users.registered_role`, and the difference is the reason the table
 * exists. `registered_role` records the intent declared once at sign-up — driver, passenger or
 * both — and is the answer to "what did this person come here for". It cannot answer "what may
 * this person do now", because:
 *
 *   - a passenger who later starts driving would need that single column MUTATED from
 *     `passenger` to `both`, destroying the only record of what she originally signed up as
 *   - there would be nowhere to put WHEN each capability began, which is what decides whether
 *     the driver walkthrough has ever been shown
 *   - one enum value cannot carry two independent statuses, so "driver side withdrawn, passenger
 *     side fine" has no representation
 *
 * So `registered_role` stays immutable history and this table is the live authority.
 *
 * `status` here is about ENROLMENT — whether the person has withdrawn from a capability herself.
 * It is deliberately NOT where staff restrictions live: an admin restricting somebody's driving
 * writes an `account_suspensions` row with `scope = driver`, so that a restriction always carries
 * a case number, a reason, an SLA and an audit trail. Conflating the two would let a restriction
 * be lifted by a self-service call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_capabilities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('capability', 20); // passenger | driver
            $table->string('status', 20)->default('active'); // active | withdrawn
            $table->dateTime('enrolled_at');
            $table->dateTime('withdrawn_at')->nullable();
            $table->timestamps();

            /*
             * 🔴 The constraint behind the acceptance criterion "repeat role submit does not
             * create duplicate capability rows".
             *
             * It is enforced here rather than only in the Action because the enrolment endpoint is
             * idempotent by contract and is called from a mobile network: a retry after a timeout
             * that already committed is the NORMAL case, not an edge case. An `if (! exists)` in
             * PHP loses that race; a unique index cannot.
             */
            $table->unique(['user_id', 'capability']);

            // Reading "what can this account do" is on the hot path — every launch routes on it.
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_capabilities');
    }
};
