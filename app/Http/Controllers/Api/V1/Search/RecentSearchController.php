<?php

namespace App\Http\Controllers\Api\V1\Search;

use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\SavedSearch;
use App\Domains\Matching\Models\SearchHistory;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecentSearchResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecentSearchController extends Controller
{
    /**
     * GET /v1/me/recent-searches — the chips under the search box (Feature 03 v5 §3).
     *
     * > *"On search-field focus show account-synced Recent Searches + `Clear All`."*
     *
     * Account-synced is the point: she searched on her phone on the bus and the same list is there
     * on a new handset. Each entry carries the criteria verbatim, so tapping one means posting it
     * straight back to the search.
     *
     * 🔒 Hers and nothing else. There is no parameter, and no endpoint anywhere reads another
     * person's — this list is where somebody lives and where she works, with timestamps.
     */
    public function index(Request $request): JsonResponse
    {
        $searches = SearchHistory::query()
            ->forUser($request->user()->id)
            ->limit((int) config('rafeeq.matching.recent_searches_kept'))
            ->get();

        return ApiResponse::success(RecentSearchResource::collection($searches));
    }

    /**
     * DELETE /v1/me/recent-searches — `Clear All`.
     *
     * 🔴 **History only.** v5 says it twice: "Clear All only clears search history" and "Clear All
     * removes history, not demands". Her saved searches ({@see SavedSearch})
     * and her private demands ({@see CommuteDemand}) are
     * deliberate things she made — one she gave a name to, the other makes the platform notify her —
     * and a button that erased those because she wanted her search box tidy would delete work she
     * cannot get back, including a standing instruction she may be relying on.
     *
     * Everything, not a page of it, and no confirmation asked for here: the client owns the
     * "are you sure" and this is the answer when she already said yes.
     */
    public function destroy(Request $request): JsonResponse
    {
        $cleared = (int) SearchHistory::query()->where('user_id', $request->user()->id)->delete();

        // How many went, so the client can say "cleared" rather than guessing whether it worked.
        return ApiResponse::success(['cleared' => $cleared]);
    }
}
