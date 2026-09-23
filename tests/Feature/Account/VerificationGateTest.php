<?php

use App\Domains\Verification\Enums\VerificationType;
use App\Http\Middleware\RequiresVerification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * The server half of the `pendingIntent` pattern (Bible §5.1): Mariam taps
 * "request a seat" with only her phone verified, so the app saves her intent,
 * sends her to verify, and brings her back to the same screen.
 *
 * For the app to do that, a refusal has to NAME what is missing — which is the
 * property these tests pin down. The first real route behind this gate arrives
 * with seat requests (Phase 7), so the middleware is exercised on a route
 * defined here; that keeps the gate's behaviour tested now rather than
 * discovered later.
 */
beforeEach(function () {
    Storage::fake('documents');

    Route::middleware(['api', 'auth:sanctum', 'verified:government_id'])
        ->get('/api/test/gated', fn () => response()->json(['reached' => true]));

    Route::middleware(['api', 'auth:sanctum', 'verified:government_id,selfie'])
        ->get('/api/test/gated-twice', fn () => response()->json(['reached' => true]));

    $this->token = signIn()['session']['accessToken'];
    completeBasicProfile($this->token);
});

it('refuses a gated action and names the level that is missing', function () {
    $response = $this->withToken($this->token)->getJson('/api/test/gated');

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'VERIFICATION_REQUIRED')
        // In `fields`, where the envelope already puts machine-readable
        // detail — no client needs a second shape to parse an error.
        ->assertJsonPath('error.fields.verification', ['government_id']);
});

it('names every missing level, not just the first', function () {
    $this->withToken($this->token)->getJson('/api/test/gated-twice')
        ->assertStatus(403)
        ->assertJsonPath('error.fields.verification', ['government_id', 'selfie']);
});

it('names only what is actually still missing', function () {
    submitGovernmentId($this->token);
    approveVerification(VerificationType::GovernmentId);

    $this->withToken($this->token)->getJson('/api/test/gated-twice')
        ->assertStatus(403)
        ->assertJsonPath('error.fields.verification', ['selfie']);
});

it('lets the action through once the level is approved', function () {
    submitGovernmentId($this->token);
    approveVerification(VerificationType::GovernmentId);

    $this->withToken($this->token)->getJson('/api/test/gated')
        ->assertOk()
        ->assertJsonPath('reached', true);
});

it('does not let a merely submitted level through', function () {
    submitGovernmentId($this->token);

    // Under review is not verified. Letting it through would mean a document
    // nobody has looked at yet unlocks the action it was required for.
    $this->withToken($this->token)->getJson('/api/test/gated')->assertStatus(403);
});

it('closes again when an approval expires', function () {
    submitGovernmentId($this->token);
    approveVerification(VerificationType::GovernmentId, expiresAt: now()->addDay());

    $this->withToken($this->token)->getJson('/api/test/gated')->assertOk();

    $this->travel(2)->days();

    fakeOtpSender();
    $token = signIn(devicePublicId: 'dev-1')['session']['accessToken'];

    $this->withToken($token)->getJson('/api/test/gated')->assertStatus(403);
});

it('answers 403 rather than 401, so the app keeps its session', function () {
    // A 401 would make the app discard a perfectly valid session and bounce
    // back to the phone screen — losing the intent it was supposed to save.
    $this->withToken($this->token)->getJson('/api/test/gated')->assertStatus(403);
});

it('refuses an unauthenticated caller before it considers verification', function () {
    // `withoutToken()` is required: `withToken()` sets a DEFAULT header on the
    // test case, and the setup above used it — so without this the request
    // would still carry a bearer token and prove nothing.
    $this->withoutToken()->getJson('/api/test/gated')->assertStatus(401);
});

it('rejects an unknown level name in the middleware arguments', function () {
    Route::middleware(['api', 'auth:sanctum', 'verified:not_a_level'])
        ->get('/api/test/gated-typo', fn () => response()->json(['reached' => true]));

    // A typo in a route definition must fail loudly, not quietly let everyone
    // through — which is what a silent `tryFrom` fallback would do.
    $this->withToken($this->token)->getJson('/api/test/gated-typo')->assertStatus(500);
});

it('lets a request with no authenticated user pass to the auth middleware', function () {
    // The middleware itself never authenticates; on a route without
    // `auth:sanctum` it simply does not apply.
    Route::middleware(['api', RequiresVerification::class.':government_id'])
        ->get('/api/test/ungated', fn () => response()->json(['reached' => true]));

    $this->withoutToken()->getJson('/api/test/ungated')->assertOk();
});
