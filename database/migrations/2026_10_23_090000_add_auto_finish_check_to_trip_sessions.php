<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When to look at a run that should have finished by now (Feature 03 v5).
 *
 * > *"Driver can finish after all passenger statuses resolved; **+15m past promised arrival** show
 * > still-driving/finish, **+5m auto-finish** only if no onboard/awaiting passengers. **Still
 * > Driving extends next check by 15m**."*
 *
 * 🔴 One column rather than two, and it holds a MOMENT rather than a count of extensions. "Still
 * driving" is not a flag whose meaning accumulates — it is the driver pushing the next look back by
 * a quarter of an hour, as many times as the road needs. A counter would have to decide what
 * happens at the fourth tap, and there is no honest answer to that: a woman stuck in Cairo traffic
 * is not more suspicious the fourth time she says so.
 *
 * Null until a run starts, because there is nothing to close before then — a day nobody began
 * expires on its own path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_sessions', function (Blueprint $table) {
            /*
             * Set when the run starts, to the promised arrival plus the grace, and pushed forward
             * by every "still driving". The sweep reads it; nothing else does.
             */
            $table->dateTime('auto_finish_check_at')->nullable()->after('completed_at');

            // How many times she said she was still going. Kept for the record, never for a
            // decision — see the class note on why a limit would be dishonest.
            $table->unsignedSmallInteger('still_driving_count')->default(0)->after('auto_finish_check_at');

            // The sweep looks for sessions past their check, so the two columns are read together.
            $table->index(['current_status', 'auto_finish_check_at']);
        });
    }

    public function down(): void
    {
        Schema::table('trip_sessions', function (Blueprint $table) {
            $table->dropIndex(['current_status', 'auto_finish_check_at']);
            $table->dropColumn(['auto_finish_check_at', 'still_driving_count']);
        });
    }
};
