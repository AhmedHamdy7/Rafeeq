<?php

use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\Artisan;

/**
 * The OpenAPI document is a deliverable the external Flutter team builds
 * against (RAFEEQ_MASTER_PLAN.md §15.4/§18), which makes it code — and it
 * gets tested like code.
 *
 * What these guard is drift: the document is generated from the routes,
 * FormRequests and attributes, so it stays correct only as long as nothing
 * silently stops describing a real behaviour. An endpoint that gains a
 * failure mode without documenting it is a contract regression even though
 * no test of the endpoint itself would notice.
 */
function openApiDocument(): array
{
    static $document = null;

    if ($document !== null) {
        return $document;
    }

    $path = storage_path('framework/testing/openapi-test.json');

    Artisan::call('scramble:export', ['--path' => $path]);

    expect(file_exists($path))->toBeTrue('scramble:export produced no document');

    return $document = json_decode(file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Every `ErrorCode` the entry points and the Action layer can actually
 * refuse with. Read from source because that is the thing that changes: a
 * new `DomainException::of(ErrorCode::X)` must show up in the contract, and
 * the only way to notice it did not is to look at where they are thrown.
 *
 * @return array<int, string>
 */
function thrownErrorCodes(): array
{
    $sources = [
        ...glob(__DIR__.'/../../app/Domains/*/Actions/*.php'),
        /*
         * 🔴 `Support/` as well, and this was a real gap. `ImageSanitiser` and `DocumentIntake`
         * both throw — they are what refuse an unreadable file, a failed virus scan and an image
         * too large to open — and none of it was in scope here, so a code thrown from a Support
         * class could reach a client without ever entering the contract. Found when
         * DOCUMENT_DIMENSIONS_TOO_LARGE was added and nothing asked for it.
         */
        ...glob(__DIR__.'/../../app/Domains/*/Support/*.php'),
        ...glob(__DIR__.'/../../app/Http/Controllers/Api/V1/*/*.php'),
    ];

    /*
     * The Admin domain is excluded, and the reason is decision D4: the dashboard is
     * Livewire with no API layer, so its Actions are unreachable from `/v1` by design
     * and their error codes have no endpoint to be documented on. Including them would
     * make this test demand that the admin surface be published — the opposite of what
     * `ScreenApiMapTest` enforces.
     *
     * Everything else stays in scope. A NEW domain added here is in scope by default,
     * which is the direction that matters.
     */
    $sources = array_filter(
        $sources,
        fn (string $file) => ! str_contains(str_replace('\\', '/', $file), '/Domains/Admin/'),
    );

    $codes = [];

    foreach ($sources as $file) {
        preg_match_all('/ErrorCode::(\w+)/', (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $case) {
            $codes[] = constant(ErrorCode::class.'::'.$case)->value;
        }
    }

    return array_values(array_unique($codes));
}

/**
 * @return array<int, array<string, mixed>>
 */
function openApiOperations(): array
{
    $operations = [];

    foreach (openApiDocument()['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            $operations[strtoupper($method).' '.$path] = $operation;
        }
    }

    return $operations;
}

it('generates a document describing every /v1 route', function () {
    $documented = array_keys(openApiOperations());

    // Asserted as a complete set, not a subset: an endpoint that quietly
    // stops being documented is exactly the failure this catches.
    expect($documented)->toEqualCanonicalizing([
        'POST /v1/auth/otp/request',
        'POST /v1/auth/otp/verify',
        'POST /v1/auth/session/refresh',
        'GET /v1/auth/me',
        'POST /v1/auth/logout',
        'GET /v1/account/devices',
        'DELETE /v1/account/devices/{device}/session',
        'PATCH /v1/account/devices/current/security',
        'GET /v1/account/consents',
        'POST /v1/account/consents',
        'PUT /v1/account/profile/basic',
        'GET /v1/account/stats',
        'GET /v1/account/verifications',
        'GET /v1/account/verifications/documents/{document}',
        'POST /v1/account/verifications/organization',
        'POST /v1/account/verifications/{type}/documents',
        'POST /v1/account/verifications/{type}/submit',
        'GET /v1/driver/eligibility',
        'GET /v1/driver/application',
        'POST /v1/driver/application',
        'PUT /v1/driver/application/licence',
        'POST /v1/driver/application/submit',
        'DELETE /v1/driver/application',
        'GET /v1/driver/vehicles',
        'POST /v1/driver/vehicles',
        'PATCH /v1/driver/vehicles/{vehicle}',
        'POST /v1/driver/vehicles/{vehicle}/activate',
        'POST /v1/driver/vehicles/{vehicle}/documents',
        'GET /v1/places',
        'GET /v1/commutes',
        'POST /v1/commutes',
        'GET /v1/commutes/{commute}',
        'PATCH /v1/commutes/{commute}',
        'DELETE /v1/commutes/{commute}',
        'PUT /v1/commutes/{commute}/route',
        'PUT /v1/commutes/{commute}/schedule',
        'POST /v1/commutes/{commute}/publish',
        'POST /v1/commutes/{commute}/pause',
        'POST /v1/commutes/{commute}/resume',
        'GET /v1/commutes/{commute}/price-suggestion',
        'GET /v1/home',
        'GET /v1/driver/home',
        'GET /v1/trips/{trip}',
        'POST /v1/trips/{trip}/start',
        'POST /v1/trips/{trip}/status',
        'POST /v1/trips/{trip}/complete',
        'GET /v1/trips/{trip}/attendance',
        'POST /v1/trips/{trip}/check-in',
        'POST /v1/trips/{trip}/no-show',
        'POST /v1/bookings/{booking}/dispute',
        'GET /v1/trips/{trip}/wait-timers',
        'POST /v1/trips/{trip}/wait-timers',
        'POST /v1/trips/{trip}/wait-timers/{timer}/extend',
        'POST /v1/trips/{trip}/location',
        'GET /v1/trips/{trip}/location',
        'POST /v1/sos',
        'POST /v1/sos/{sos}/cancel',
        'GET /v1/safety/emergency-contacts',
        'POST /v1/safety/emergency-contacts',
        'PATCH /v1/safety/emergency-contacts/{contact}',
        'DELETE /v1/safety/emergency-contacts/{contact}',
        'GET /v1/safety/blocked-users',
        'POST /v1/safety/blocked-users',
        'DELETE /v1/safety/blocked-users/{user}',
        /*
         * Share Live Trip — the authenticated side only. The page a contact opens is `GET /s/{token}`
         * on the WEB router, and it must never appear in this document: it is a page for a person,
         * and listing it in the API contract would invite a client to call it with a token it
         * invented.
         */
        'POST /v1/trips/{trip}/live-share',
        'GET /v1/safety/live-shares',
        'DELETE /v1/safety/live-shares/{share}',
        // Ratings (Phase 10). Nothing here returns somebody else's rating — see RatingController.
        'GET /v1/ratings/pending',
        'GET /v1/ratings/mine',
        'POST /v1/bookings/{booking}/rating',
        'PATCH /v1/ratings/{rating}',
        'GET /v1/incidents',
        'GET /v1/incidents/{incident}',
        'POST /v1/incidents',
        'GET /v1/incidents/{incident}/evidence',
        'POST /v1/incidents/{incident}/evidence',
        'GET /v1/search/commutes',
        'GET /v1/commute-demands',
        'POST /v1/commute-demands',
        'DELETE /v1/commute-demands/{demand}',
        'GET /v1/matches',
        'GET /v1/saved-searches',
        'POST /v1/saved-searches',
        'DELETE /v1/saved-searches/{search}',

        // Phase 7 — seat requests, bookings and groups (Chapter 6, §889).
        'POST /v1/commutes/{commute}/seat-requests',
        'GET /v1/seat-requests',
        'DELETE /v1/seat-requests/{seatRequest}',
        'GET /v1/my-bookings',
        'GET /v1/bookings/{booking}',
        'PATCH /v1/bookings/{booking}/cancel',
        'GET /v1/driver/seat-requests',
        'POST /v1/driver/seat-requests/{seatRequest}/approve',
        'POST /v1/driver/seat-requests/{seatRequest}/reject',
        'GET /v1/driver/bookings',

        'POST /v1/commutes/{commute}/pickup-preview',
        'POST /v1/driver/seat-requests/{seatRequest}/waitlist',
        'POST /v1/seat-requests/{seatRequest}/pickup-request',
        'POST /v1/groups/{group}/pickup-request',
        'GET /v1/pickup-requests',
        'POST /v1/pickup-requests/{pickupRequest}/accept-alternative',
        'GET /v1/driver/pickup-requests',
        'POST /v1/driver/pickup-requests/{pickupRequest}/approve',
        'POST /v1/driver/pickup-requests/{pickupRequest}/suggest-alternative',
        'POST /v1/driver/pickup-requests/{pickupRequest}/reject',

        'GET /v1/groups',
        'GET /v1/groups/{group}',
        'GET /v1/groups/{group}/members',
        'POST /v1/groups/{group}/leave',
        'GET /v1/groups/{group}/attendance',
        'POST /v1/groups/{group}/attendance',
        'GET /v1/groups/{group}/absences',
        'POST /v1/groups/{group}/absences',
        'DELETE /v1/groups/{group}/absences/{absence}',
    ]);
});

it('carries the API identity the mobile team needs', function () {
    $document = openApiDocument();

    expect($document['info']['title'])->toBe('RAFEEQ API')
        ->and($document['info']['version'])->toBe('1.0.0')
        // The envelope, the localisation header and the single-use refresh
        // rule are the three things a client gets wrong without being told.
        ->and($document['info']['description'])->toContain('meta')
        ->and($document['info']['description'])->toContain('Accept-Language')
        ->and($document['info']['description'])->toContain('Retry-After')
        ->and(array_column($document['servers'], 'description'))->toEqualCanonicalizing(['Local', 'Staging']);
});

/**
 * `ApiResponse::success()` drops the key entirely when there is no pagination
 * metadata, so a client model that requires it would fail to parse every
 * response we currently send.
 */
it('describes meta as optional and never as a required null', function () {
    $checked = 0;

    foreach (openApiOperations() as $name => $operation) {
        foreach ($operation['responses'] as $status => $response) {
            $schema = $response['content']['application/json']['schema'] ?? null;

            if ($schema === null || ! array_key_exists('meta', $schema['properties'] ?? [])) {
                continue;
            }

            $checked++;

            // `toContain` is variadic and takes no message, so the check is
            // written as a boolean to keep the operation name in the failure.
            expect(in_array('meta', $schema['required'] ?? [], true))
                ->toBeFalse("{$name} {$status} lists meta as required")
                ->and($schema['properties']['meta']['type'])
                ->toEqualCanonicalizing(['object', 'null'], "{$name} {$status}");
        }
    }

    expect($checked)->toBeGreaterThan(0, 'no success response mentioned meta — the assertion above proved nothing');
});

it('describes the error envelope for every failure it documents', function () {
    foreach (openApiOperations() as $name => $operation) {
        foreach ($operation['responses'] as $status => $response) {
            if ((int) $status < 400) {
                continue;
            }

            $schema = $response['content']['application/json']['schema'];

            expect($schema['required'])->toEqualCanonicalizing(['success', 'error'], "{$name} {$status}")
                ->and($schema['properties']['error']['required'])->toEqualCanonicalizing(['code', 'message', 'fields'])
                // A client switches on the code, so the codes reachable at
                // this status have to be enumerated, not left to prose.
                ->and($schema['properties']['error']['properties']['code']['enum'])->not->toBeEmpty("{$name} {$status}");
        }
    }
});

it('documents every error code the Actions and controllers can throw', function () {
    $documented = [];

    foreach (openApiOperations() as $operation) {
        foreach ($operation['responses'] as $status => $response) {
            $enum = $response['content']['application/json']['schema']['properties']['error']['properties']['code']['enum'] ?? [];
            $documented = [...$documented, ...$enum];
        }
    }

    $undocumented = array_diff(thrownErrorCodes(), array_unique($documented));

    expect($undocumented)->toBeEmpty(
        'These codes can be thrown but appear in no documented response. Add them to the '
        .'endpoint\'s #[ApiErrors] attribute: '.implode(', ', $undocumented)
    );
});

it('puts every documented code at the status its catalogue entry declares', function () {
    foreach (openApiOperations() as $name => $operation) {
        foreach ($operation['responses'] as $status => $response) {
            $enum = $response['content']['application/json']['schema']['properties']['error']['properties']['code']['enum'] ?? [];

            foreach ($enum as $value) {
                expect(ErrorCode::from($value)->defaultStatus())
                    ->toBe((int) $status, "{$name}: {$value} documented under {$status}");
            }
        }
    }
});

it('always tells a rate-limited client when to come back', function () {
    foreach (openApiOperations() as $name => $operation) {
        if (! isset($operation['responses'][429])) {
            continue;
        }

        // `toHaveKey` takes the expected VALUE second, so the message has to
        // be named — passing it positionally would assert the value instead.
        expect($operation['responses'][429]['headers'] ?? [])
            ->toHaveKey('Retry-After', message: "{$name} returns 429 without Retry-After");
    }
});

/**
 * A document that marks a protected endpoint as public is worse than no
 * document: it invites a client to call it without a token and treat the 401
 * as a bug. The requirement is derived from route middleware, so this test
 * is really checking that derivation still holds.
 */
it('marks public endpoints as public and everything else as bearer-protected', function () {
    $document = openApiDocument();

    expect($document['security'])->toBe([['bearerAuth' => []]])
        ->and($document['components']['securitySchemes']['bearerAuth']['scheme'])->toBe('bearer');

    $public = ['POST /v1/auth/otp/request', 'POST /v1/auth/otp/verify', 'POST /v1/auth/session/refresh'];

    foreach (openApiOperations() as $name => $operation) {
        if (in_array($name, $public, true)) {
            expect($operation['security'] ?? null)->toBe([], "{$name} should require no credential");

            continue;
        }

        // Absent means "inherit the document-level bearerAuth requirement".
        expect($operation)->not->toHaveKey('security', message: "{$name} should require a bearer token");
    }
});

it('never documents the admin dashboard or any non-API route', function () {
    foreach (array_keys(openApiDocument()['paths']) as $path) {
        expect($path)->toStartWith('/v1/');
    }
});

/**
 * A field described as "an array of something" is a field the mobile team has
 * to guess at, and a guess about a payload is a runtime crash waiting for the
 * first unusual value.
 *
 * Asserted against the PUBLISHED document rather than through the generator's
 * own `--fail-on-unknown` flag: that flag validates the internal type tree
 * before serialisation and reports types here that come out perfectly well
 * typed, so wiring it in would mean living with a check that cries wolf. This
 * looks at the artifact the team actually consumes.
 */
it('describes every field concretely, with no untyped holes', function () {
    $holes = [];

    $walk = function (mixed $schema, string $path) use (&$walk, &$holes): void {
        if (! is_array($schema)) {
            return;
        }

        $describesItself = array_intersect_key($schema, array_flip(
            ['type', '$ref', 'allOf', 'anyOf', 'oneOf', 'enum', 'const'],
        )) !== [];

        if (! $describesItself) {
            $holes[] = $path;
        }

        if (array_key_exists('items', $schema)) {
            $walk($schema['items'], "{$path}/items");
        }

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $walk($property, "{$path}/{$name}");
        }

        if (is_array($schema['additionalProperties'] ?? null)) {
            $walk($schema['additionalProperties'], "{$path}/*");
        }
    };

    foreach (openApiOperations() as $name => $operation) {
        foreach ($operation['responses'] as $status => $response) {
            if (isset($response['content']['application/json']['schema'])) {
                $walk($response['content']['application/json']['schema'], "{$name} {$status}");
            }
        }
    }

    foreach (openApiDocument()['components']['schemas'] ?? [] as $schemaName => $schema) {
        $walk($schema, "components/{$schemaName}");
    }

    expect($holes)->toBeEmpty('Untyped schema nodes: '.implode(', ', $holes));
});
