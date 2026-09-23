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

    'verification' => [
        /*
         * DEPLOY-ONLY. Which filesystem disk holds identity documents.
         *
         * Decision D7: a private S3-compatible bucket, never a public URL.
         * The default `documents` disk is private local storage for
         * development; production sets this to `s3`. It is not runtime-tunable
         * because moving the disk out from under existing rows would orphan
         * every document already stored.
         */
        'documents_disk' => env('RAFEEQ_DOCUMENTS_DISK', 'documents'),

        /*
         * DEPLOY-ONLY. Whether a document link points straight at storage
         * (a presigned URL, decision D7) or at our own signed route.
         *
         * An explicit flag rather than asking the disk what it supports: that
         * capability is not stable. `Storage::fake()` makes EVERY disk claim it
         * can presign, so a capability check silently takes a different branch
         * under test than in production — which is the one place the difference
         * must not exist, because the two branches have different security
         * properties. Turn this on wherever `documents_disk` is S3.
         */
        'presigned_document_urls' => env('RAFEEQ_PRESIGNED_DOCUMENT_URLS', false),

        /*
         * DEPLOY-ONLY. Which scanner inspects an upload before it can be
         * submitted for review. `signature` is the development scanner and
         * refuses to run in production, so shipping without a real engine
         * fails loudly instead of waving malware through.
         */
        'virus_scanner' => env('RAFEEQ_VIRUS_SCANNER', 'signature'),

        // How long a document access link stays valid. Short because the link
        // is the only thing standing between a leaked URL and someone's
        // national ID.
        'document_url_ttl_seconds' => 120,

        'max_document_size_kilobytes' => 8192,

        // Attempts before a verification type is locked and needs an admin to
        // reopen it — an upload loop is the cheapest way to probe what a
        // reviewer accepts.
        'max_submission_attempts' => 5,
    ],

    'driver' => [
        // Chapter 3 §7: "vehicle year configurable". The floor is a policy
        // decision about what the platform is willing to put passengers in,
        // not a technical limit.
        'vehicle_minimum_year' => 2005,

        // §7: "seats between 2 and 8". This is the vehicle's TOTAL seats
        // including the driver; bookable seats are always one fewer.
        'vehicle_minimum_seats' => 2,
        'vehicle_maximum_seats' => 8,

        'maximum_vehicles_per_driver' => 3,

        /*
         * A licence must still be valid this far into the future before an
         * application is accepted. Reviewing takes 24–48 hours (§4), so a
         * licence expiring tomorrow would be approved and immediately
         * worthless — and the driver would have published commutes by then.
         */
        'licence_minimum_validity_days' => 30,
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
