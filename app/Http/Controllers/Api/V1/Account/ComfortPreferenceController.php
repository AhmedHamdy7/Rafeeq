<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Actions\UpdateComfortPreferencesAction;
use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Models\UserPreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateComfortPreferencesRequest;
use App\Http\Resources\ComfortPreferenceResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ComfortPreferenceController extends Controller
{
    /**
     * GET /v1/account/comfort-preferences — the saved chips for both modes.
     *
     * ⚠️ v3 proposes `?mode=...`. Both modes come back in one call instead, because the screen is
     * one entry whose content changes with the active mode (v3 §178) — so the app must switch
     * without a round trip, and a required parameter buys an extra request and an invalid-mode
     * error path for two short lists.
     *
     * Reading never writes a row: somebody who has never opened this screen gets `null` back
     * without us recording that she looked.
     */
    public function index(Request $request): JsonResponse
    {
        $preference = UserPreference::forUser($request->user());

        /*
         * `chosen` keeps "never answered" apart from "none of them" all the way out to the client.
         * Collapsing them would turn somebody who has not answered into somebody who refused every
         * chip — and only a real answer should ever be handed to a search.
         */
        return ApiResponse::success(new ComfortPreferenceResource($preference));
    }

    /**
     * PATCH /v1/account/comfort-preferences — replace one mode's saved chips.
     *
     * 🔒 Saving a default filters nothing. These reach the matcher as `requiredRules` on a search,
     * where they are a soft `comfort_score` and never an exclusion — v3: "avoid claiming these are
     * strict filters until matching rules are defined." Remembering her answer changes which
     * searches are prefilled, not which commutes exist.
     */
    public function update(UpdateComfortPreferencesRequest $request, UpdateComfortPreferencesAction $action): JsonResponse
    {
        $preference = $action->execute(
            $request->user(),
            Capability::from((string) $request->validated('mode')),
            $request->validated('rules'),
        );

        // Both modes come back, not just the one that changed: the screen shows whichever mode is
        // active, and a PATCH that returned half the state would make the other half stale.
        return ApiResponse::success(new ComfortPreferenceResource($preference));
    }
}
