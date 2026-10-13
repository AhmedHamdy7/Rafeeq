<?php

namespace App\Domains\Identity\Enums;

use App\Domains\Identity\Support\LaunchRouter;

/**
 * Where the app should send the person next. Never persisted.
 *
 * 🔴 These five replace the four that came before, and the change is breaking rather than additive:
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
     * A DRIVER-scope restriction is NOT this — that person keeps full passenger access and goes
     * Home, with the driver surface refusing on its own. See `AccountRestriction`.
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

    /** Nothing outstanding. The client reads capabilities and the active mode to decide which home. */
    case Home = 'HOME';

    /*
     * 🔴 `ROLE_SELECTION` is deliberately NOT here yet.
     *
     * It belongs to the next slice, with `user_capabilities` and `initial_role_selected_at`. Adding
     * the case now would publish a value the server cannot yet return — which is exactly the fault
     * that cost a correction in the mobile guide a week ago, when it listed `VERIFY_IDENTITY` as a
     * `nextStep` and the mobile team could have branched on something that never arrives.
     */
}
