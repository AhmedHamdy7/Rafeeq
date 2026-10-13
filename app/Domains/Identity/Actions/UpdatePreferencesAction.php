<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\ThemePreference;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPreference;

/**
 * Recording UI state (Feature 01 v3: `PATCH /me/preferences`, "Update mode/walkthrough seen
 * without granting capabilities").
 *
 * 🔒 The last four words are the security property. Nothing in here touches `user_capabilities`,
 * `registered_role` or any restriction, so no amount of creativity with this payload can widen
 * what the caller is allowed to do.
 */
final readonly class UpdatePreferencesAction
{
    /**
     * @param  array<string, mixed>  $attributes  already validated upstream
     */
    public function execute(User $user, array $attributes): UserPreference
    {
        $preference = UserPreference::forUser($user);

        if (array_key_exists('activeMode', $attributes)) {
            $preference->last_active_mode = Capability::from((string) $attributes['activeMode']);
        }

        if (array_key_exists('language', $attributes)) {
            $preference->language = (string) $attributes['language'];
        }

        if (array_key_exists('theme', $attributes)) {
            $preference->theme = ThemePreference::from((string) $attributes['theme']);
        }

        /*
         * 🔴 The two "seen" flags are one-way, and only ever set the FIRST time.
         *
         * They are timestamps, not booleans, and the timestamp is the point: it is what tells
         * support whether somebody had seen the safety explanation before a particular trip. A
         * later `true` must therefore not move the date to today, or that answer quietly becomes
         * wrong — and a `false` must not erase it, which is why there is no path back to unseen.
         *
         * Re-showing a walkthrough is a thing a person asks support for, and it would need a
         * deliberate reset with an audit trail rather than a field in a preferences PATCH.
         */
        if (($attributes['onboardingSeen'] ?? false) === true && $preference->onboarding_seen_at === null) {
            $preference->onboarding_seen_at = now();
        }

        if (($attributes['driverWalkthroughSeen'] ?? false) === true && $preference->driver_walkthrough_seen_at === null) {
            $preference->driver_walkthrough_seen_at = now();
        }

        $preference->save();

        return $preference;
    }
}
