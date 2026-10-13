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

            /*
             * 🔴 Role missing answers BASIC_PROFILE, not ROLE_SELECTION, and that is deliberate.
             *
             * F01 §5: "if the user exits before completing role selection, resume at Basic Profile
             * (prefilled and editable), then Role; do not skip straight to Role" — and the
             * acceptance criteria repeat it: resume at Basic Profile "even if profile fields are
             * already populated". The person gets to see what she entered before being asked the
             * next question.
             *
             * `ROLE_SELECTION` comes from {@see self::nextStepAfterBasicProfile()} instead.
             */
            $user->initial_role_selected_at === null => NextStep::BasicProfile,

            default => NextStep::Home,
        };
    }

    /**
     * The answer for the one caller that has just accepted a basic profile.
     *
     * 🔴 A separate method rather than a flag on the one above, because the same user state has to
     * produce two different answers and the reason is not guessable from a boolean. The failure
     * mode is also worse than a wrong screen: if this call site returned `BASIC_PROFILE` for a
     * person with no role — which is what the cold-start rule says — the app would submit the
     * profile, be told to submit the profile, and loop for ever.
     *
     * No `$device`: unlike a cold start there is nothing about the installation that could matter
     * here, and taking an argument this never reads would invite somebody to start reading it.
     */
    public static function nextStepAfterBasicProfile(User $user): NextStep
    {
        return match (true) {
            $user->account_status === AccountStatus::Suspended => NextStep::RestrictedAccount,

            // Still incomplete after a successful save means the save did not include everything,
            // so the person stays where she is rather than being advanced past a gap.
            $user->profile_status !== ProfileStatus::BasicComplete => NextStep::BasicProfile,

            /*
             * The role may already be set, because a client that still sends `registeredRole` with
             * the profile — every client live today — has just chosen it in the same request. Those
             * go straight Home and never see the new screen, which is what makes the split additive
             * at the API edge.
             */
            $user->initial_role_selected_at === null => NextStep::RoleSelection,

            default => NextStep::Home,
        };
    }
}
