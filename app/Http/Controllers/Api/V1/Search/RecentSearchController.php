<?php

namespace App\Http\Controllers\Api\V1\Search;

use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\SavedSearch;
use App\Domains\Matching\Models\SearchHistory;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
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

    /**
     * DELETE /v1/me/recent-searches/{search} — remove one row (DISCOVER-8, handoff B-2).
     *
     * The swipe on a single entry, beside `Clear All`. Deleted outright rather than hidden: this is
     * the one table where keeping something she asked to be rid of is a privacy decision, not a
     * storage one. Still history only — a demand or a saved search with the same route is untouched.
     *
     * 🔒 Scoped to her own list, and 404 for anybody else's id: a 403 would confirm the id exists,
     * and what is behind it is where somebody lives.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function destroyOne(Request $request, string $search): JsonResponse
    {
        $deleted = (int) SearchHistory::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($search)
            ->delete();

        if ($deleted === 0) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return ApiResponse::success(['deleted' => true]);
    }
}
