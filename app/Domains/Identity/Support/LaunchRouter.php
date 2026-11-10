<?php

namespace App\Domains\Identity\Support;

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
     * `$device` is kept in the signature on purpose. Most callers already hold it, removing it
     * would touch six call sites for no gain, and the moment a device fact matters again — a
     * revoked installation, say — this is where it belongs.
     *
     * It is OPTIONAL rather than required because `GET /account/onboarding-state` legitimately has
     * no device to offer: it answers "where was this sign-up up to", which is a fact about the
     * ACCOUNT. Forcing it to produce one would have meant querying for an arbitrary device and
     * passing it to a parameter nothing reads — a fake argument is worse than an absent one.
     */
    public static function nextStepFor(User $user, ?Device $device = null): NextStep
    {
        return match (true) {
            /*
             * 🔒 Outranks everything. A banned account must never be routed Home whatever else
             * is true about it.
             *
             * 🔴 Only a PERMANENT ban (AUTH-19, D-0018, follow-up F1-3). A suspension stays in the
             * app: it falls through to the steps below and normally lands on `HOME`, where the
             * app shows the banner from `user.suspension` with Appeal, and every write is refused
             * by `account.active` with `ACCOUNT_SUSPENDED`.
             */
            $user->isBanned() => NextStep::RestrictedAccount,

            $user->profile_status !== ProfileStatus::BasicComplete => NextStep::BasicProfile,

            $user->initial_role_selected_at === null => NextStep::RoleSelection,

            default => NextStep::Home,
        };
    }
}
