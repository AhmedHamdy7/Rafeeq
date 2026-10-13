<?php

namespace App\Domains\Identity\Enums;

use App\Domains\Identity\Support\LaunchRouter;

/**
 * Where the app should send the person next. Never persisted.
 *
 * 🔴 These five replace a different four, and the change was breaking rather than additive:
 * `CREATE_PIN` and `LOCAL_SECURITY_SETUP_OR_HOME` existed only because of the local PIN, and the PIN
 * is out of the MVP (Feature 01 §10). So the set could not be extended — it had to be replaced, and
 * the mobile guide's change log says so in one entry rather than two.
 *
 * Deliberately a bare enum: binding standard #12 keeps every domain `Enums` namespace free of
 * Laravel, so the rules that read Eloquent models live in {@see LaunchRouter} instead.
 */
enum NextStep: string
{
    /**
     * No usable session. Returned by the refresh endpoint when a refresh token is dead, so the
     * client has one vocabulary for "go back to the phone screen" rather than inferring it from a
     * 401.
     */
    case PhoneOtp = 'PHONE_OTP';

    /**
     * 🔒 An ACCOUNT-scope restriction. Outranks everything else: a restricted account must never be
     * routed Home whatever else is true about it.
     *
     * A DRIVER-only restriction is NOT this — that person keeps full passenger access and goes
     * Home, with the driver surface refusing on its own. That restriction lives in
     * `driver_profiles.status`, not here: `GET /driver/eligibility` reports it as the
     * `driver_suspended` blocker, and `CreateCommuteOfferAction`, `SeatAvailabilityChecker` and
     * `HardFilters` each refuse independently.
     */
    case RestrictedAccount = 'RESTRICTED_ACCOUNT';

    /**
     * The basic profile, prefilled and editable.
     *
     * 🔴 Returned while the profile is incomplete **or the initial role was never chosen**, and the
     * second half is a deliberate product decision rather than an oversight (F01 §5): somebody who
     * closed the app on the role screen comes back to the profile, sees what they entered, and
     * continues forward. Dropping them straight on Role would show a screen with no context behind
     * it.
     */
    case BasicProfile = 'BASIC_PROFILE';

    /**
     * Passenger, Driver or Both — the last step of onboarding.
     *
     * 🔴 Returned by `PUT /account/profile/basic` and by nothing else, which is the opposite of
     * what the decision tree's "role missing → ROLE_SELECTION" reads like, so it is written down
     * here rather than left to be rediscovered.
     *
     * A cold start with no role answers `BASIC_PROFILE`, because F01 §5 says to resume there
     * "even if profile fields are already populated". Only submitting the profile moves somebody
     * forward to Role. Letting the launch router return this instead would mean a person who
     * closed the app on the role screen lands straight back on it with no context behind it —
     * and letting the profile endpoint return `BASIC_PROFILE` would be an infinite loop: submit
     * the profile, be told to submit the profile.
     */
    case RoleSelection = 'ROLE_SELECTION';

    /** Nothing outstanding. The client reads capabilities and the active mode to decide which home. */
    case Home = 'HOME';
}
