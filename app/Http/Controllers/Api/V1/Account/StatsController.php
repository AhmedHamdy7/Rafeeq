<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Models\UserStat;
use App\Http\Controllers\Controller;
use App\Http\Resources\PersonSummary;
use App\Http\Resources\UserStatsResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The numbers on the caller's own profile screen.
 *
 * `user_stats` exists because of ERD §23.1 gap #2 — the profile screen shows "12 trips ·
 * ⭐ 4.9 · 96% on-time" and the passenger had nowhere for those to come from. The table was
 * built in Phase 1 and then nothing ever returned it, so the screen has been unbuildable
 * since.
 *
 * 🔒 Own stats only. There is no route that returns another member's numbers as a block:
 * what one person may know about another is the narrower {@see PersonSummary},
 * which appears inside a match card or a member list where there is a reason to see it.
 * A general "stats for any user id" endpoint would make the platform's whole membership
 * enumerable by anybody with an account.
 */
final class StatsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $stats = UserStat::query()->whereKey($request->user()->id)->first();

        /*
         * A missing row is the normal state for a new account, not an error: the stats
         * are written by a job that runs after trips complete. `UserStatsResource` reads
         * a null into "nothing yet" rather than into zeroes, because a new passenger has
         * no on-time rate and showing 0% would be an accusation.
         */
        return ApiResponse::success(new UserStatsResource($stats));
    }
}
