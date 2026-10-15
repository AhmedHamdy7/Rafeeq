<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Actions\UpdateComfortPreferencesAction;
use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Models\UserComfortPreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateComfortPreferencesRequest;
use App\Http\Resources\ComfortPreferenceResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ComfortPreferenceController extends Controller
{
    /**
     * GET /v1/account/comfort-preferences — both modes at once.
     *
     * ⚠️ v3 proposes `?mode=...`. Returning BOTH instead, because the screen that shows this is one
     * entry whose content changes with the active mode (v3 §178) — so the app has to be able to
     * switch modes without a round trip, and a required query parameter buys an extra request and
     * an invalid-mode error path for nothing. It is four fields twice.
     *
     * Reading never writes a row: somebody who has never opened this screen gets the defaults back
     * without us recording that they looked.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'passenger' => new ComfortPreferenceResource(
                UserComfortPreference::forUserAndMode($user, Capability::Passenger)
            ),
            'driver' => new ComfortPreferenceResource(
                UserComfortPreference::forUserAndMode($user, Capability::Driver)
            ),
        ]);
    }

    /**
     * PATCH /v1/account/comfort-preferences — one mode's answers.
     *
     * 🔒 Stores and returns, and nothing else. These are **not** matching filters: v3 says "avoid
     * claiming these are strict filters until matching rules are defined", so nothing in search
     * reads them. A passenger who asked for a quiet car and got a chatty driver is mildly
     * disappointed; a passenger silently excluded from every commute because she ticked a box has
     * an empty result she cannot explain.
     */
    public function update(UpdateComfortPreferencesRequest $request, UpdateComfortPreferencesAction $action): JsonResponse
    {
        $preference = $action->execute(
            $request->user(),
            Capability::from((string) $request->validated('mode')),
            $request->validated(),
        );

        return ApiResponse::success(new ComfortPreferenceResource($preference));
    }
}
