<?php

namespace App\Http\Resources;

use App\Domains\Identity\Models\UserPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UI state kept across devices (Feature 01 v3: `user_preferences`).
 *
 * The two "seen" facts are returned as booleans rather than the timestamps behind them. The dates
 * are kept server-side because support needs to know WHEN somebody saw the safety explanation, but
 * a client only ever asks whether to show a screen — and publishing the timestamp would invite it
 * to start comparing dates and inventing its own rule about when to show it again.
 *
 * @mixin UserPreference
 */
final class PreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Which home opens next launch. A preference, never a permission.
            'activeMode' => $this->last_active_mode?->value,
            'onboardingSeen' => $this->onboarding_seen_at !== null,
            'driverWalkthroughSeen' => $this->driver_walkthrough_seen_at !== null,
            // Used for notifications the server sends; a response's language follows
            // `Accept-Language` on the request instead.
            'language' => $this->language,
            'theme' => $this->theme?->value,
        ];
    }
}
