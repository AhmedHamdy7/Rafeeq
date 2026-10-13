<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Actions\EnrollInitialRoleAction;
use App\Domains\Identity\Actions\UpdatePreferencesAction;
use App\Domains\Identity\Enums\RegisteredRole;
use App\Domains\Identity\Support\LaunchRouter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\EnrollInitialRoleRequest;
use App\Http\Requests\Account\UpdatePreferencesRequest;
use App\Http\Resources\OnboardingStateResource;
use App\Http\Resources\PreferenceResource;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The end of onboarding: choosing a role, recovering an interrupted sign-up, and the UI
 * preferences that go with them (Feature 01 v3 §§4–5).
 *
 * ⚠️ These live under `/account`, not the `/me` the handoff proposes. The repository already
 * serves this person's own account under `/account/*` — including `PUT /account/profile/basic`,
 * which is the step immediately before these and is live with the mobile team. Adding a second
 * namespace for the same resource would mean the guide documenting two base paths for one account,
 * and v3's own delivery contract asks for exactly this adaptation: "reuse compatible conventions,
 * do not duplicate APIs".
 */
final class OnboardingController extends Controller
{
    /**
     * POST /v1/account/initial-role — Passenger, Driver or Both.
     *
     * Idempotent: enrolling twice is the same as enrolling once, because this is the last call of
     * a sign-up and a retry after a dropped connection must not fail.
     */
    public function storeInitialRole(EnrollInitialRoleRequest $request, EnrollInitialRoleAction $action): JsonResponse
    {
        $user = $action->execute(
            $request->user(),
            RegisteredRole::from((string) $request->validated('role')),
        );

        return ApiResponse::success([
            'user' => new UserResource($user),
            // Home once the role is in, which is the only outstanding step by this point.
            'nextStep' => LaunchRouter::nextStepAfterBasicProfile($user)->value,
        ]);
    }

    /**
     * GET /v1/account/onboarding-state — where an interrupted sign-up resumes.
     *
     * Safe to call at launch before anything else is known. It reads what was saved and answers
     * with a decision rather than with facts for the client to recombine.
     */
    public function state(Request $request): JsonResponse
    {
        return ApiResponse::success(new OnboardingStateResource($request->user()));
    }

    /**
     * PATCH /v1/account/preferences — mode, walkthrough flags, language, theme.
     *
     * 🔒 Grants nothing. v3 asks for preferences to be updatable "without granting capabilities",
     * so a client that sets `activeMode: driver` without the driver capability gets the preference
     * stored and every protected driver action still refused.
     */
    public function updatePreferences(UpdatePreferencesRequest $request, UpdatePreferencesAction $action): JsonResponse
    {
        $preference = $action->execute($request->user(), $request->validated());

        return ApiResponse::success(new PreferenceResource($preference));
    }
}
