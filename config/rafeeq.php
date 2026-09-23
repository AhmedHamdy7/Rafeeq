<?php

/*
|--------------------------------------------------------------------------
| Rafeeq domain configuration
|--------------------------------------------------------------------------
|
| These are the SHIPPED DEFAULTS only. Almost everything here is overridable
| at runtime through `platform_settings` (binding standard #11: no magic
| number lives in code), read via the per-domain settings accessors —
| `Identity\Support\AuthSettings`, `Identity\Support\ProfileSettings`,
| `Identity\Support\ConsentRegistry`. Never read this file directly from an
| Action, a Request or a Resource: go through the accessor, so the admin
| dashboard can retune the platform without a deploy.
|
| The one exception is marked DEPLOY-ONLY below. Infrastructure wiring must
| not be reachable from a dashboard: a mistyped transport there would take
| authentication down for everyone, and unlike a policy number it cannot be
| validated by looking at it.
|
| Where a value bounds a database column, the column width is the real
| ceiling — raising the setting past it turns a validation error into a
| truncated write. Those ceilings are noted inline.
|
*/

return [

    /*
     * The legal documents in force right now. Bumping a version here is what
     * makes every account's consent stale, so the app can require a
     * re-acceptance — which is the entire point of storing a version
     * alongside each consent rather than a bare boolean.
     */
    'legal' => [
        'terms_version' => env('RAFEEQ_TERMS_VERSION', '1.0'),
        'privacy_version' => env('RAFEEQ_PRIVACY_VERSION', '1.0'),
    ],

    'profile' => [
        /*
         * ASSUMPTION, flagged for product/legal sign-off: Chapter 2 §17.3
         * requires a minimum age ("do not allow users below the minimum
         * supported age") but never states the number, and neither do the
         * Master Plan nor the Bible. 18 is used because it is the age of
         * legal majority in Egypt and the minimum driving-licence age, so it
         * is the only figure that works for drivers and passengers alike.
         *
         * Runtime-tunable precisely BECAUSE it is an assumption: when the
         * real policy lands it is a dashboard edit, not a release.
         */
        'minimum_age_years' => 18,

        // Chapter 2 §17.3: "two to fifty characters". Ceiling: `users
        // .full_name` is varchar(100), so a max above 100 would truncate.
        'full_name_min_length' => 2,
        'full_name_max_length' => 50,
    ],

    'auth' => [

        'otp' => [
            /*
             * DEPLOY-ONLY — never expose this in the admin dashboard.
             *
             * Which transport actually delivers the code. The SMS provider is
             * still open question #2 in RAFEEQ_MASTER_PLAN.md §19, so `log` is
             * the only driver that exists today — it refuses to run in
             * production on purpose, so shipping without a real provider
             * fails loudly instead of silently swallowing every code.
             */
            'driver' => env('RAFEEQ_OTP_DRIVER', 'log'),

            // Chapter 2 §23.2 shows a six-digit code. This is the OTP, not the
            // local PIN (which is four digits per MASTER_PLAN §13).
            'length' => 6,

            // Chapter 2 §12: "2 to 5 minutes" lifetime, "30 to 60 seconds"
            // resend cooldown.
            'ttl_seconds' => 120,
            'resend_cooldown_seconds' => 45,

            'max_attempts' => 5,
            'max_resends' => 3,
        ],

        'session' => [
            // Short-lived access token + long-lived rotating refresh token
            // (Chapter 2 §23.3). Keep the access window small: a stolen-device
            // revocation (scenario G) only bites once the access token dies.
            // Exactly the figures in the Bible's §1.3 walkthrough.
            'access_ttl_minutes' => 15,
            'refresh_ttl_days' => 60,
        ],

        'pin' => [
            /*
             * Informational for the client only — the server NEVER sees a PIN
             * value, it only records `devices.has_local_pin`. Chapter 2 says
             * six digits; MASTER_PLAN §13 resolves that conflict in favour of
             * the prototype's four. Exposed so the app reads one source of
             * truth instead of hard-coding it.
             */
            'length' => 4,
        ],

        /*
         * Abuse controls (Chapter 2 §26, scenario D). Three independent axes:
         * one phone cannot be harassed with SMS, one IP cannot enumerate, and
         * one challenge cannot be brute-forced.
         */
        'rate_limits' => [
            'otp_requests_per_phone_per_hour' => 5,
            'otp_requests_per_ip_per_hour' => 20,
            'otp_verifications_per_challenge_per_minute' => 10,
        ],
    ],

];
