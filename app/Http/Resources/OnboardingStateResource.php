<?php

namespace App\Http\Resources;

use App\Domains\Identity\Enums\CapabilityStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserCapability;
use App\Domains\Identity\Support\LaunchRouter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Server-derived recovery routing (Feature 01 v3: `GET /me/onboarding-state`).
 *
 * 🔴 The point of this endpoint is that the SERVER decides where an interrupted sign-up resumes.
 * The app may have been killed mid-step, reinstalled, or be running on a second device, and in
 * each case the only trustworthy answer is the one computed from what was actually saved. So this
 * returns the decision, not the ingredients — `nextStep` is the field to act on, and the booleans
 * beside it are there to let a screen explain itself ("we kept your name"), not to be re-combined
 * into a routing rule the client invents for itself.
 *
 * @mixin User
 */
final class OnboardingStateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['capabilities', 'preference']);

        $preference = $this->preference;

        return [
            /*
             * Where to go now. Computed by the same router that answers sign-in and refresh, so
             * the three can never disagree.
             *
             * A cold start with the profile saved but no role chosen answers `BASIC_PROFILE`, not
             * `ROLE_SELECTION` — by design (F01 §5): the person sees what she entered, confirms
             * it, and is then asked for the role.
             */
            'nextStep' => LaunchRouter::nextStepFor($this->resource)->value,

            'profileComplete' => $this->profile_status->value === 'basic_complete',
            'initialRoleSelected' => $this->initial_role_selected_at !== null,
            'capabilities' => $this->capabilityValues(),

            // What the person already saw, so a resumed sign-up does not replay it.
            'onboardingSeen' => $preference?->onboarding_seen_at !== null,
            'driverWalkthroughSeen' => $preference?->driver_walkthrough_seen_at !== null,
            'activeMode' => $preference?->last_active_mode?->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function capabilityValues(): array
    {
        return $this->capabilities
            ->where('status', CapabilityStatus::Active)
            ->map(fn (UserCapability $row): string => strtoupper($row->capability->value))
            ->values()
            ->all();
    }
}
