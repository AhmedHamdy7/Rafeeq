<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\NextStep;
use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;

/**
 * The one implementation of the launch router (Feature 01 v3, `next_step` decision tree).
 *
 * It lives server-side, and every response that tells the app where to go — sign-in, refresh,
 * session status — goes through it, so the three can never disagree. Leaving it to the client would
 * make a restricted account whose tokens are still perfectly valid a routing decision the client
 * could simply get wrong.
 */
final class LaunchRouter
{
    /**
     * Ordered by precedence, and the order is the point.
     *
     * 🔴 What changed with v3, and why it could not be additive: the PIN left the MVP, and the two
     * values that existed only because of it — `CREATE_PIN` and `LOCAL_SECURITY_SETUP_OR_HOME` —
     * went with it. A device is no longer part of this decision at all: a valid refresh token
     * silently restores the session and nothing is asked of the person in between.
     *
     * `$device` is kept in the signature on purpose. Every caller already holds it, removing it
     * would touch six call sites for no gain, and the moment a device fact matters again — a
     * revoked installation, say — this is where it belongs.
     */
    public static function nextStepFor(User $user, Device $device): NextStep
    {
        return match (true) {
            /*
             * 🔒 Outranks everything. A restricted account must never be routed Home whatever else
             * is true about it.
             *
             * ⚠️ Today this reads `account_status`, which is all-or-nothing. v3 splits restriction
             * into ACCOUNT and DRIVER scope, and a DRIVER-scope restriction must route Home with
             * full passenger access — so when `account_restrictions` lands, this line narrows to
             * the ACCOUNT scope rather than growing a second case.
             */
            $user->account_status === AccountStatus::Suspended => NextStep::RestrictedAccount,

            /*
             * An unfinished profile is resumed, never skipped. v3 §5 extends this: once the initial
             * role exists, somebody who closed the app on the role screen also comes back HERE
             * rather than being dropped on Role with no context behind it.
             */
            $user->profile_status !== ProfileStatus::BasicComplete => NextStep::BasicProfile,

            default => NextStep::Home,
        };
    }
}
