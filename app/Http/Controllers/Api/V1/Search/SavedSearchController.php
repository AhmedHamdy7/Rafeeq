<?php

namespace App\Http\Controllers\Api\V1\Search;

use App\Domains\Matching\Models\SavedSearch;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Matching\StoreSavedSearchRequest;
use App\Http\Resources\SavedSearchResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SavedSearchController extends Controller
{
    /**
     * GET /v1/saved-searches — the caller's own saved filter sets.
     */
    public function index(Request $request): JsonResponse
    {
        $searches = SavedSearch::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(SavedSearchResource::collection($searches));
    }

    /**
     * POST /v1/saved-searches — remembers a set of filters to re-run later.
     *
     * Saving the same filters twice returns the existing row rather than refusing.
     * Chapter 5 lists "duplicate saved searches" as an edge case, and a unique
     * index on the signature is what actually prevents it — but the person tapping
     * save again meant "keep this", not "create a second one", so answering with
     * the row they already have is the honest outcome.
     */
    public function store(StoreSavedSearchRequest $request): JsonResponse
    {
        $filters = $request->validated('filters');

        $search = SavedSearch::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                // A hash of the filters, so "the same search" is decided by what it
                // asks for and not by the title someone gave it.
                'signature' => hash('sha256', json_encode($filters)),
            ],
            [
                'title' => $request->validated('title'),
                'filters' => $filters,
            ],
        );

        return ApiResponse::success(new SavedSearchResource($search), status: 201);
    }

    /**
     * DELETE /v1/saved-searches/{search}.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function destroy(Request $request, string $search): JsonResponse
    {
        $saved = SavedSearch::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($search)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $saved->delete();

        return ApiResponse::success(['deleted' => true]);
    }
}
