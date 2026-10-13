<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Actions\CompleteBasicProfileAction;
use App\Domains\Identity\Support\LaunchRouter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\CompleteBasicProfileRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class ProfileController extends Controller
{
    /**
     * PUT /v1/account/profile/basic — minimal profile setup (Chapter 2 §17).
     *
     * Idempotent on purpose: scenario J has the person losing connectivity
     * mid-setup and resuming later, so a repeat submission must succeed
     * rather than conflict.
     */
    public function storeBasic(CompleteBasicProfileRequest $request, CompleteBasicProfileAction $action): JsonResponse
    {
        $user = $action->execute($request->user(), $request->validated());

        /*
         * 🔴 `nextStepAfterBasicProfile`, NOT `nextStepFor`.
         *
         * A cold start with no role resumes at `BASIC_PROFILE` (F01 §5), but this request IS the
         * profile submission — answering `BASIC_PROFILE` here would tell the app to submit the
         * profile it just submitted, for ever. The two rules disagree on purpose, which is why
         * they are two methods.
         *
         * It also no longer needs the session: the step is now a fact about the account, so a
         * missing session can no longer make this `null`.
         */
        return ApiResponse::success([
            'user' => new UserResource($user),
            'nextStep' => LaunchRouter::nextStepAfterBasicProfile($user)->value,
        ]);
    }
}
