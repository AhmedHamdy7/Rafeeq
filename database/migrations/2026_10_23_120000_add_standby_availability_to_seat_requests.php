<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Not available today" on a waiting-list membership (Feature 03 v5 §8).
 *
 * > *"`Available Today` / `Not Available Today` on standby management; reversible repeatedly.
 * > Turning unavailable excludes the member from today's automatic offers, preserves future
 * > recurring membership; returning available doesn't restore closed offers. No penalty."*
 *
 * 🔴 **A date, not the boolean v5's sketch suggests** (`waiting_list_requests(... availability_today
 * ...)`). A flag called "available today" has to be un-set by something, and nothing would: a woman
 * who taps it once on a Tuesday is quietly excluded from every morning after it until she
 * remembers. A nightly job to clear the column would be a second mechanism that fails silently —
 * and the day it does not run, nobody is offered a seat and the symptom is an empty car.
 *
 * Storing the DAY she meant means the statement expires by itself: tomorrow, `unavailable_on`
 * refers to a date that is no longer today and the fan-out ignores it. It also makes the eligibility
 * filter a plain comparison against `scheduled_trips.trip_date` — both are local dates, so there is
 * no timezone arithmetic anywhere near the seat race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            /*
             * The one local date she declared herself unavailable for. Null is the normal state:
             * everybody on a waiting list is available until she says otherwise, which is the
             * default v5 asks for.
             */
            $table->date('unavailable_on')->nullable()->after('waitlist_position');
        });
    }

    public function down(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->dropColumn('unavailable_on');
        });
    }
};
