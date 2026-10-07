<?php

namespace App\Http\Controllers\Api\V1\Search;

use App\Domains\Matching\Actions\SearchCommutesAction;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\RateLimits;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Matching\SearchCommutesRequest;
use App\Http\Resources\MatchResultResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

final class SearchController extends Controller
{
    /**
     * GET /v1/search/commutes — Chapter 5.
     *
     * Rafeeq never dispatches. This finds commutes a driver already published and
     * ranks them for the person asking.
     *
     * Rate limited per account, as Chapter 5 requires: the pipeline ends in
     * routing-provider work, and an unbounded search endpoint is a way to spend
     * someone else's money.
     */
    #[ApiErrors(ErrorCode::TooManyRequests)]
    public function commutes(SearchCommutesRequest $request, SearchCommutesAction $action): JsonResponse
    {
        $this->assertWithinRateLimit($request->user()->id);

        $results = $action->execute($request->user(), $request->criteria());

        return ApiResponse::success(
            MatchResultResource::collection($results),
            // An empty result is not an error — Chapter 5 turns it into the offer
            // to save a private demand, so the client needs to tell the two apart.
            meta: ['matched' => count($results)],
        );
    }

    private function assertWithinRateLimit(string $userId): void
    {
        if (! RateLimits::enabled()) {
            return;
        }

        $key = 'search:commutes:'.$userId;
        $limit = (int) config('rafeeq.matching.searches_per_user_per_minute');

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw DomainException::retryAfter(
                ErrorCode::TooManyRequests,
                RateLimiter::availableIn($key),
            );
        }

        RateLimiter::hit($key, 60);
    }
}
