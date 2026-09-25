<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a level was handed to a reviewer.
 *
 * The review queue orders by this and shows "waiting 3h 20m" from it, and both of those
 * are promises rather than decoration: oldest-first is what stops somebody sitting
 * behind new arrivals indefinitely, and the waiting time is the number a reviewer's
 * responsiveness gets judged against.
 *
 * `updated_at` was the obvious stand-in and is the wrong one. It moves for any write to
 * the row, so the first time anything else touches a pending verification the queue
 * silently reorders and the person who has waited longest drops down it — a fairness bug
 * that would look like nothing at all.
 *
 * Backfilled from `updated_at` for rows that are already pending, which is the best
 * available answer for them and better than a null that sorts unpredictably.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_verifications', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('method');

            // The queue reads exactly this: pending rows, oldest submission first.
            $table->index(['status', 'submitted_at']);
        });

        DB::table('user_verifications')
            ->where('status', 'pending')
            ->whereNull('submitted_at')
            ->update(['submitted_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('user_verifications', function (Blueprint $table) {
            $table->dropIndex(['status', 'submitted_at']);
            $table->dropColumn('submitted_at');
        });
    }
};
