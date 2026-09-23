<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\NextStep;
use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;

/**
 * The one implementation of Chapter 2's launch-router ordering (§3).
 *
 * It lives server-side, and every response that tells the app where to go
 * — sign-in, refresh, session status — goes through it, so the three can
 * never disagree. Leaving this to the client would make scenario H (a
 * suspended account whose tokens are still perfectly valid) a routing
 * decision the client could simply get wrong.
 */
final class LaunchRouter
{
    /**
     * Ordered by precedence, and the order is the point:
     *
     * 1. Suspension outranks everything — a suspended account must never be
     *    routed Home, whatever else is true about it.
     * 2. A device with no local PIN sets one before anything else, so a
     *    reinstall (scenario B) is asked again on the new installation
     *    rather than inheriting the old one's trust.
     * 3. An unfinished profile is resumed (scenario J), never skipped.
     */
    public static function nextStepFor(User $user, Device $device): NextStep
    {
        return match (true) {
            $user->account_status === AccountStatus::Suspended => NextStep::AccountSuspended,
            ! $device->has_local_pin => NextStep::CreatePin,
            $user->profile_status !== ProfileStatus::BasicComplete => NextStep::CompleteProfile,
            default => NextStep::LocalSecuritySetupOrHome,
        };
    }
}
