<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feature 03 R-1 → R-4 and R-7: what a request is asking for, a passenger's pause, and the end of
 * the 48-hour request clock.
 *
 * 1. **`kind`** — `seat_request` or `waitlist_application` (see `SeatRequestKind`). Every row that
 *    exists today was a seat request, which is what the default says.
 * 2. **`paused_at` / `paused_until`** — set on her waiting-list place while she has paused her seat
 *    (BOOK-32). `paused_until` null with `paused_at` set means "no end date". While paused she is
 *    not offered a seat; she keeps her place in the queue.
 * 3. **The clock comes off every open request that is not a during-ride one.** Requests never
 *    expire now (R-4, decision C2-note); the old rows still carry the `now + 48h` the old rule
 *    stamped, and leaving it would let the during-ride sweep — which reads `expires_at` — close
 *    them the next minute. Only the during-ride three minutes keeps a deadline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->string('kind', 24)->default('seat_request')->after('commitment');

            $table->dateTime('paused_at')->nullable()->after('displaced_reason');
            $table->date('paused_until')->nullable()->after('paused_at');
        });

        DB::table('seat_requests')
            ->where('requested_during_ride', false)
            ->whereIn('status', ['pending', 'waitlisted', 'counteroffered'])
            ->update(['expires_at' => null]);
    }

    public function down(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->dropColumn(['kind', 'paused_at', 'paused_until']);
        });
    }
};
