<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\PublicProfileResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PublicProfileController extends Controller
{
    /**
     * GET /v1/users/{user}/public-profile — a member's profile as a stranger sees it
     * (Feature 02 v3).
     *
     * 🔒 **Signed in, despite the name.** v3 calls these "public viewers", which in the app means
     * other members — and this is the one payload in the API reachable for an ARBITRARY user id.
     * Left open to the internet it is a scraper's index of the platform's drivers: names, trust
     * levels, ratings, trip counts and join dates, enumerable by id. Requiring a session does not
     * make that impossible, but it makes it attributable and rate-limited, which is the difference
     * between a leak and a log entry.
     *
     * It is NOT behind the verified tier: a passenger needs no identity verification to look at the
     * driver she is about to ride with. Checking her out is the thing we most want her to do.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function show(string $user): JsonResponse
    {
        $person = User::query()->whereKey($user)->first();

        /*
         * 404 for a deleted or suspended account as well as a missing one, and the same 404 for
         * all three.
         *
         * A suspended person's profile is not a thing to browse while staff are deciding, and
         * distinguishing the cases would turn this into an oracle: "this id exists but is
         * suspended" is a fact about somebody that nobody outside the safety team is owed.
         * `SoftDeletes` already hides a deleted account from the query.
         */
        if ($person === null || $person->account_status !== AccountStatus::Active) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return ApiResponse::success(new PublicProfileResource($person));
    }
}
