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
    /**
     * How many of the caller's commutes are published right now. Set by the PATCH that changes
     * the mode (F2-10), so a driver switching to the passenger side can be reminded they keep running.
     */
    public int $publishedCommutesCount = 0;

    public function withPublishedCommutesCount(int $count): self
    {
        $this->publishedCommutesCount = $count;

        return $this;
    }

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
            // F2-10: published commutes keep running, and passengers keep expecting the driver,
            // until he pauses them. Remind him when he switches to `passenger` and this is above 0.
            'publishedCommutesCount' => $this->publishedCommutesCount,
        ];
    }
}
