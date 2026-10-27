<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The driver-not-started ladder (Feature 03 v5 §240).
 *
 * > *"Driver not started: +1m reminder; +5m passenger warning; +10m violation/escalation; +20m
 * > auto-EXPIRED only if never started. If started at +12m, remains IN_PROGRESS; existing violation
 * > remains."*
 *
 * 🔴 **Columns rather than deriving "already warned" from the inbox.** A sweep that runs every
 * minute must not send the +5 warning fifteen times, so each rung needs a record that it fired.
 * Reading that back from `notifications` would work today and break in ninety days: the inbox is
 * pruned on a retention clock, and the moment a row ages out the sweep would warn everybody again
 * about a morning from last season.
 *
 * They also answer a question support will be asked — *"why didn't she get the warning?"* — which a
 * derived answer could only guess at.
 *
 * ⚠️ **The last clause of v5's sentence needs no code**, and that is worth saying because it looks
 * like it does. "If started at +12m, remains IN_PROGRESS; existing violation remains": the sweep
 * only ever looks at days that have not started, so starting removes a day from its reach, and
 * nothing clears a stamp once set. Both halves fall out of the design rather than being enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            // +1m: she is reminded that her commute was due to leave. Once.
            $table->dateTime('delay_driver_reminded_at')->nullable()->after('receives_requests_during_ride');

            // +5m: everybody holding a seat is told the driver has not set off. Once each.
            $table->dateTime('delay_passengers_warned_at')->nullable()->after('delay_driver_reminded_at');

            /*
             * +10m: v5's "violation/escalation", as a durable stamp.
             *
             * ⚠️ Nothing acts on it yet and there is no ops surface that reads it — the escalation
             * v5 describes needs a desk workflow that does not exist. It is recorded now because it
             * is a fact about a morning, and a fact nobody wrote down at the time cannot be
             * reconstructed later from a trip that eventually ran.
             *
             * 🔴 And deliberately NOT a reliability event. The score measures harm to people, and
             * harm here is per passenger — the per-booking events at +20 carry it. A flat penalty
             * at this rung would charge a driver for not driving a Thursday nobody had booked.
             */
            $table->dateTime('delay_violation_at')->nullable()->after('delay_passengers_warned_at');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->dropColumn([
                'delay_driver_reminded_at',
                'delay_passengers_warned_at',
                'delay_violation_at',
            ]);
        });
    }
};
