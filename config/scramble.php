<?php

use App\Http\OpenApi\BearerTokenSecurity;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

/*
|--------------------------------------------------------------------------
| OpenAPI documentation (Scramble)
|--------------------------------------------------------------------------
|
| This document is a deliverable, not a convenience: RAFEEQ_MASTER_PLAN.md
| §15.4 makes the external Flutter team's contract the reason /v1 may never
| break, and §18 puts staging in their hands from the end of Phase 2. What
| this file describes is what they build against.
|
| Only the defaults that matter are overridden here; everything else stays on
| the package default so upgrades are boring.
|
*/

return [

    /*
     * Everything under /api is documented. There is exactly one API surface
     * (the mobile app); the Livewire admin dashboard is not an API and must
     * never appear here.
     */
    'api_path' => 'api',

    'api_domain' => null,

    'export_path' => 'storage/app/openapi.json',

    'info' => [
        /*
         * Tracks the URL version, not the release: /v1 is a promise that this
         * document only ever grows. A breaking change means /v2 and a new
         * document, never a bump here (§15.4).
         */
        'version' => env('API_VERSION', '1.0.0'),

        'description' => <<<'MARKDOWN'
        RAFEEQ — planned commute sharing. **Not** an on-demand ride-hailing API.

        ## Response envelope

        Every response, success or failure, uses one envelope:

        ```json
        { "success": true, "data": { ... } }
        { "success": false, "error": { "code": "AUTH_OTP_EXPIRED", "message": "...", "fields": null } }
        ```

        `meta` appears **only** on paginated collections (`{ "page": 1, "total": 42 }`);
        it is absent otherwise, so treat it as optional. `error.fields` is a
        map of field name to messages, and is `null` for errors that are not
        about a specific field.

        Branch on `error.code`, never on `error.message` — the message is
        localised and is written to be shown to the person as-is.

        ## Localisation

        Send `Accept-Language: ar` or `en`. Anything else falls back to the
        signed-in account's stored preference, then to Arabic. Every
        `error.message` and every validation message honours it.

        ## Authentication

        Two tokens, two jobs:

        - **Access token** — short-lived, sent as `Authorization: Bearer <token>`.
        - **Refresh token** — long-lived, single-use, sent in the body of
          `POST /v1/auth/session/refresh`, which replaces both.

        A refresh token is rotated on every use. Presenting one twice is
        treated as theft: every session in its family is revoked and the
        person must verify their phone again. Store it in OS secure storage
        and never retry a refresh with a token that already succeeded.

        ## Rate limits

        OTP endpoints are limited per phone number, per IP, and per challenge.
        A `429` always carries `Retry-After` in seconds — honour it rather
        than backing off on your own schedule.
        MARKDOWN,
    ],

    'ui' => [
        'title' => 'RAFEEQ API',
    ],

    /*
     * Named so the mobile team can point Stoplight at staging without
     * editing anything. Local first, because that is where the document is
     * generated and previewed.
     */
    'servers' => [
        'Local' => 'api',
        'Staging' => env('API_STAGING_URL', 'https://staging.rafeeq.app/api'),
    ],

    /*
     * The docs UI is not public. `RestrictedDocsAccess` allows it in local
     * only unless a `viewApiDocs` gate says otherwise — the document
     * describes every endpoint and every error code of an unreleased
     * product, so exposing it is a decision, not a default.
     */
    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    /*
     * Derives each operation's auth requirement from its route middleware —
     * the only source that cannot drift out of step with reality. A class
     * string, not the `[class, options]` form the package documents: that
     * form puts a SecurityScheme object in config, and `config:cache`
     * serialises config with var_export, which cannot express one. See the
     * class for the scheme itself.
     */
    'security_strategy' => BearerTokenSecurity::class,

    'extensions' => [],
];
