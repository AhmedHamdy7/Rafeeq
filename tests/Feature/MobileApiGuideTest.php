<?php

use Illuminate\Support\Facades\Route;

/**
 * Guards `Rafeeq doc/RAFEEQ_MOBILE_API_GUIDE.md` — the document the Flutter team builds against.
 *
 * 🔴 This exists because the user asked for a file that stays current with every change, and a
 * promise to remember is not a mechanism. The screen map already rotted once this way: the ERD's
 * §23 reviewed all 47 screens, concluded "47/47 covered", and was accurate when written — then
 * stayed cited as evidence for months while the entire admin surface did not exist.
 *
 * A guide that drifts is worse than no guide, because the mobile team cannot tell which half is
 * stale. So this enforces the property that matters in BOTH directions:
 *
 *   1. every endpoint the guide documents must be a real route — otherwise it promises something
 *      renamed or never built, and somebody writes a client against it
 *   2. every live route must be documented — otherwise work we finished is invisible to the team
 *      waiting for it, which is the whole reason the file was asked for
 *
 * What it deliberately does NOT check: whether a documented FIELD still exists. That needs the
 * response shapes, which the OpenAPI document generates from the code and which
 * `OpenApiDocumentTest` already guards. A test that pretended to check prose would be the same
 * false comfort §23 turned into.
 */
function mobileApiGuide(): string
{
    static $contents = null;

    return $contents ??= (string) file_get_contents(
        __DIR__.'/../../Rafeeq doc/RAFEEQ_MOBILE_API_GUIDE.md'
    );
}

/**
 * Every `METHOD /path` the guide claims exists.
 *
 * Read from the `#### ` endpoint headings and from the inline `` `GET /x` `` mentions in the
 * screen index, because both are promises to the reader.
 *
 * @return array<int, string>
 */
function guideEndpoints(): array
{
    preg_match_all(
        '/`(GET|POST|PUT|PATCH|DELETE)\s+(\/[a-z0-9\-\/{}]+)`/i',
        mobileApiGuide(),
        $matches,
        PREG_SET_ORDER,
    );

    $found = [];

    foreach ($matches as $match) {
        $found[] = strtoupper($match[1]).' '.rtrim($match[2], '/');
    }

    return array_values(array_unique($found));
}

/**
 * Every `METHOD /path` the application actually serves, as the guide writes them — without the
 * `/v1` prefix, since the guide states the base URL once and then omits it.
 *
 * @return array<int, string>
 */
function liveEndpointsAsGuideWritesThem(): array
{
    $live = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_starts_with($uri, '/api/v1/')) {
            continue;
        }

        $path = substr($uri, strlen('/api/v1'));

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $live[] = $method.' '.$path;
        }
    }

    return array_values(array_unique($live));
}

it('documents no endpoint that does not exist', function () {
    $live = liveEndpointsAsGuideWritesThem();

    /*
     * The guide also mentions two paths that are not `/v1` routes: the broadcasting auth endpoint
     * and the docs page. Both are real and both are outside the versioned API, so they are named
     * here rather than silently skipped by a loose pattern.
     */
    $outsideV1 = ['POST /broadcasting/auth', 'GET /docs/api'];

    $promised = array_diff(guideEndpoints(), $outsideV1);

    $missing = array_values(array_diff($promised, $live));

    expect($missing)->toBeEmpty(
        "The mobile guide documents endpoints that do not exist. Either the route was renamed \n"
        ."and the guide was not updated, or it was never built: \n- ".implode("\n- ", $missing)
    );
});

/**
 * 🔴 The direction that matters most. An endpoint we shipped and did not document is work the
 * mobile team cannot see — and being able to see it is the entire reason this file exists.
 */
it('documents every endpoint the API actually serves', function () {
    $undocumented = array_values(array_diff(liveEndpointsAsGuideWritesThem(), guideEndpoints()));

    expect($undocumented)->toBeEmpty(
        "These endpoints are live and appear nowhere in the mobile guide. The Flutter team has \n"
        ."no way to know they exist: \n- ".implode("\n- ", $undocumented)
        ."\n\nAdd them to section 4, and to the screen index if they serve a designed screen."
    );
});

/**
 * The counts in the header are the first thing anybody reads, and a wrong one makes the rest
 * suspect.
 */
it('states the real number of endpoints in its header', function () {
    $count = count(liveEndpointsAsGuideWritesThem());

    expect(mobileApiGuide())->toContain("**{$count} endpoints live**");
});

/**
 * Every screen the designer produced has to appear, so a new one cannot be designed and silently
 * never mapped. Numbers rather than names, because the names in the prototype are shortened in
 * places and a name check would fail on formatting instead of on substance.
 */
it('accounts for all 47 designed screens', function (int $screen) {
    // Anywhere in the leading id cell, because rows combine screens: `| 16 · 32 · 44 |`.
    expect(preg_match('/^\|[\s\d·]*\b'.$screen.'\b[\s\d·]*\|/m', mobileApiGuide()))->toBe(
        1,
        "Screen {$screen} appears in no row of the mobile guide's screen index."
    );
})->with(range(1, 47));

/**
 * The reader is told the guide is kept current. That claim has to be checked against something,
 * and the cheapest honest check is that the date moves when the endpoints do.
 */
it('carries a last-updated date and a change log', function () {
    expect(mobileApiGuide())
        ->toContain('**Last updated:**')
        ->toContain('## 9. Change log')
        // The maintenance promise names this test. If the name changes, the promise is stale.
        ->toContain('tests/Feature/MobileApiGuideTest.php');
});

/**
 * 🔒 The guide is handed to an external team, so it must not become a place where a privacy rule
 * is quietly restated wrongly. These four are the ones a client author would otherwise assume the
 * opposite of, and each is enforced in code elsewhere.
 */
it('states the privacy rules a client author would otherwise guess wrong', function () {
    $guide = mobileApiGuide();

    $rules = [
        'publicFirstName' => 'that a public first name is the only name returned about others',
        'fuzzed' => 'that a meeting point is fuzzed until the booking is confirmed',
        'plateNumber' => 'that the plate number is withheld until confirmation',
        'monthly ceiling' => 'that the budget is monthly, not per seat',
    ];

    foreach ($rules as $needle => $what) {
        // `toBeTrue` with a message rather than `toContain`, which treats a second argument as
        // another needle to look for.
        expect(str_contains($guide, $needle))->toBeTrue(
            "The mobile guide no longer says {$what}. That is a rule a client author will get "
            .'backwards if we do not tell them.'
        );
    }
});
