<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Actions\CompleteBasicProfileAction;
use App\Domains\Identity\Support\CurrentSession;
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

        $session = CurrentSession::for($request);

        return ApiResponse::success([
            'user' => new UserResource($user),
            'nextStep' => $session === null
                ? null
                : LaunchRouter::nextStepFor($user, $session->device)->value,
        ]);
    }
}
