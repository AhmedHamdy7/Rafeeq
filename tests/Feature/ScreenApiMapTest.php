<?php

use Illuminate\Support\Facades\Route;

/**
 * Guards `Rafeeq doc/RAFEEQ_SCREEN_API_MAP.md` — the document the mobile and
 * dashboard teams build against.
 *
 * This file exists because of a specific, already-realised failure. The ERD's §23
 * reviewed all 47 screens against the schema, concluded "47/47 covered", and was
 * accurate when written — but it was written before a single endpoint existed, so it
 * says nothing about whether the API a screen needs is actually there. By Phase 7 it
 * was still being cited as evidence of coverage while the entire admin surface, which
 * gates the mobile app's happy path, did not exist.
 *
 * A map that depends on somebody remembering to update it rots exactly that way. So:
 *
 *   1. every endpoint the map names must be a real route — otherwise the map promises
 *      something that was renamed or never built
 *   2. every screen and dashboard section must appear — otherwise a new screen can be
 *      designed and silently never mapped
 *
 * What these deliberately do NOT check: whether a screen's FIELDS are all present.
 * That needs reading the screen, and a test that pretended to do it would be the same
 * false comfort §23 turned into.
 */
function screenApiMap(): string
{
    static $contents = null;

    return $contents ??= (string) file_get_contents(__DIR__.'/../../Rafeeq doc/RAFEEQ_SCREEN_API_MAP.md');
}

/**
 * Every `/v1/...` path the application actually serves.
 *
 * @return array<int, string>
 */
function registeredV1Paths(): array
{
    $paths = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_starts_with($uri, '/api/v1/')) {
            continue;
        }

        $paths[] = substr($uri, 4);
    }

    return array_values(array_unique($paths));
}

it('names only endpoints that really exist', function () {
    $registered = registeredV1Paths();

    /*
     * Endpoints written inside a table cell as `GET /v1/foo` or as a bare
     * `/v1/foo/{bar}`. The section listing what is MISSING is excluded by name
     * below — those are proposals, not claims.
     */
    $claims = str_contains(screenApiMap(), '## 10. ملخص الـ endpoints الناقصة')
        ? strstr(screenApiMap(), '## 10. ملخص الـ endpoints الناقصة', before_needle: true)
        : screenApiMap();

    preg_match_all('#`(?:GET|POST|PUT|PATCH|DELETE) (/v1/[^`\s]+)`#', $claims, $matches);

    $unknown = [];

    foreach (array_unique($matches[1]) as $path) {
        // Route parameters are named differently in the map than in the route
        // definition often enough that comparing them literally would fail for no
        // useful reason; the shape is what matters.
        $normalise = fn (string $p) => preg_replace('/\{[^}]+\}/', '{}', rtrim($p, '/'));

        $found = false;

        foreach ($registered as $candidate) {
            if ($normalise($candidate) === $normalise($path)) {
                $found = true;

                break;
            }
        }

        if (! $found) {
            $unknown[] = $path;
        }
    }

    expect($unknown)->toBeEmpty(
        'The screen map promises these endpoints to the mobile team, and no route serves them. '
        ."Either the route was renamed or the map is describing something unbuilt: \n- "
        .implode("\n- ", $unknown)
    );
});

/**
 * The 47 mobile screens, by the banner name each one carries in the prototype's own
 * source. Read from the prototype once and written down here, because the prototype is
 * a bundled artefact this suite cannot parse — so the list is pinned, and a screen
 * added to the design shows up as a map gap rather than as nothing at all.
 */
it('maps every screen in the prototype', function (string $screen) {
    // `str_contains` + `toBeTrue`, not `toContain($screen, $message)` — the second
    // argument to `toContain` is another needle to look for, not a message.
    expect(str_contains(screenApiMap(), $screen))->toBeTrue(
        "Screen [{$screen}] exists in the prototype and appears nowhere in the screen map, "
        .'so nobody knows whether an API serves it.'
    );
})->with([
    'SPLASH / WELCOME', 'LOGIN', 'USE ANOTHER ACCOUNT', 'FORGOT PIN', 'PHONE', 'OTP',
    'BASIC PROFILE', 'ROLE', 'HOME', 'DISCOVER', 'FILTERS', 'MATCH DETAILS',
    'SEAT REQUEST', 'REQUEST DONE', 'VERIFICATION', 'SAFETY CENTRE', 'ACTIVE TRIP',
    'CREATE COMMUTE REQUEST', 'TRIPS', 'COMMUTE GROUP', 'PROFILE', 'NOTIFICATIONS',
    'DRIVER HOME', 'PUBLISH ROUTE', 'PUBLISH REVIEW', 'PRIVACY & BLOCKED',
    'HELP & LEGAL', 'DRIVER REQUEST REVIEW', 'CUSTOM PICKUP APPROVAL',
    'IDENTITY CAPTURE', 'VEHICLE CAPTURE', 'SUPPORT / INCIDENT', 'OFFLINE',
    'PAYMENT FAILED', 'ACCOUNT RESTRICTED', 'PRE-TRIP CHECK-IN', 'RATING',
    'LOCATION DENIED', 'ROUTE CHANGED', 'NO-SHOW', 'DRIVER WAIT TIMER',
    'MATCHING SPINNER', 'DRIVER CANCELLED', 'DISCREET SAFETY ALERT',
    'DRIVER CANCEL CONFIRMATION', 'CANCEL TODAY', 'DIRECTION',
]);

it('maps every section of the admin dashboard', function (string $section) {
    expect(str_contains(screenApiMap(), $section))->toBeTrue(
        "Dashboard section [{$section}] appears nowhere in the screen map."
    );
})->with([
    'DASHBOARD', 'LIVE TRIPS', 'VERIFICATION', 'SAFETY CASES', 'MEMBERS',
    'CORRIDORS', 'PAYMENTS', 'AUDIT LOG', 'SETTINGS',
]);

/**
 * 🔒 Decision D4: the admin dashboard is Livewire in this project, and there is
 * deliberately NO API layer for it — "لغة واحدة، مفيش API layer للأدمن".
 *
 * So this is a rule, not a reminder. An admin JSON endpoint appearing under `/v1`
 * means either D4 was overturned (in which case the Master Plan and the map's section
 * 9 change first) or somebody exposed the admin surface to the internet by accident.
 * The second is the reason this is a test: the admin side approves drivers and reads
 * every verification document on the platform, and it authenticates by session
 * against a separate user table that has no token guard at all.
 */
it('keeps the admin surface off the public API, per decision D4', function () {
    $adminRoutes = array_filter(
        registeredV1Paths(),
        fn (string $path) => str_starts_with($path, '/v1/admin'),
    );

    expect($adminRoutes)->toBeEmpty(
        "Decision D4 says the dashboard is Livewire with no admin API layer, and these \n"
        ."routes are on the versioned public API: \n- ".implode("\n- ", $adminRoutes)
        ."\n\nIf D4 was overturned, change the Master Plan and section 9 of the screen map first."
    );
});
