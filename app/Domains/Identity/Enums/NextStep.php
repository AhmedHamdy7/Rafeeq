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
     * The basic profile, prefilled and editable, returned while the profile is incomplete.
     */
    case BasicProfile = 'BASIC_PROFILE';

    /**
     * Passenger, Driver or Both, returned once the profile is saved and no role was chosen (AUTH-02).
     */
    case RoleSelection = 'ROLE_SELECTION';

    /** Nothing outstanding. The client reads capabilities and the active mode to decide which home. */
    case Home = 'HOME';
}
