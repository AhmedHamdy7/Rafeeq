<?php

use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Enums\SessionRevocationReason;
use App\Domains\Identity\Models\AuthSession;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Identity\Support\AuthSettings;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Chapter 2 §23.3 and scenario G.
 */
function refresh(string $token)
{
    return test()->postJson('/api/v1/auth/session/refresh', ['refreshToken' => $token]);
}

it('rotates the refresh token and returns a working new pair', function () {
    $signIn = signIn();
    $original = $signIn['session']['refreshToken'];

    $refreshed = refresh($original)->assertOk()->json('data');

    expect($refreshed['session']['refreshToken'])->not->toBe($original)
        ->and($refreshed['session']['accessToken'])->not->toBe($signIn['session']['accessToken']);

    // The new access token actually authenticates.
    $this->withToken($refreshed['session']['accessToken'])
        ->getJson('/api/v1/auth/me')->assertOk();
});

it('keeps the rotated session in the same family and links it to its predecessor', function () {
    $signIn = signIn();

    refresh($signIn['session']['refreshToken'])->assertOk();

    $sessions = AuthSession::orderBy('created_at')->get();

    expect($sessions)->toHaveCount(2)
        ->and($sessions[1]->token_family_id)->toBe($sessions[0]->token_family_id)
        ->and($sessions[1]->previous_session_id)->toBe($sessions[0]->id)
        ->and($sessions[0]->revocation_reason)->toBe(SessionRevocationReason::Rotated)
        ->and($sessions[0]->last_refreshed_at)->not->toBeNull();
});

it('never stores the refresh token itself, only a hash of it', function () {
    $signIn = signIn();
    $token = $signIn['session']['refreshToken'];

    expect(AuthSession::sole()->refresh_token_hash)->toBe(hash('sha256', $token))
        ->and(AuthSession::sole()->toArray())->not->toHaveKey('refresh_token_hash');
});

/**
 * The heart of §23.3: reuse of a rotated token means it was captured, so
 * the whole family dies and both parties must re-authenticate.
 */
it('revokes the entire token family when a rotated token is presented again', function () {
    $signIn = signIn();
    $stolen = $signIn['session']['refreshToken'];

    // The legitimate app refreshes first.
    $live = refresh($stolen)->assertOk()->json('data.session.refreshToken');

    // The thief replays the old token.
    refresh($stolen)->assertStatus(401)
        ->assertJsonPath('error.code', 'AUTH_SESSION_REUSE_DETECTED');

    expect(AuthSession::whereNull('revoked_at')->count())->toBe(0)
        ->and(PersonalAccessToken::count())->toBe(0)
        ->and(SecurityEvent::where('event_type', SecurityEventType::TokenReuse->value)->count())->toBe(1);

    // And the token the legitimate app is holding is dead too — there is no
    // way to tell which of the two was the thief.
    refresh($live)->assertStatus(401)
        ->assertJsonPath('error.code', 'AUTH_SESSION_REUSE_DETECTED');
});

it('treats a logged-out token as merely stale, without signing other devices out', function () {
    $first = signIn(devicePublicId: 'dev-1');

    fakeOtpSender();
    $second = signIn(phone: '01012345678', devicePublicId: 'dev-2');

    $this->withToken($first['session']['accessToken'])
        ->postJson('/api/v1/auth/logout')->assertOk();

    refresh($first['session']['refreshToken'])->assertStatus(401)
        ->assertJsonPath('error.code', 'AUTH_SESSION_INVALID');

    // The other device is untouched.
    refresh($second['session']['refreshToken'])->assertOk();
});

it('rejects a refresh token that never existed', function () {
    refresh(str_repeat('a', 64))->assertStatus(401)
        ->assertJsonPath('error.code', 'AUTH_SESSION_INVALID');
});

it('refuses to refresh past the refresh window', function () {
    $signIn = signIn();

    $this->travel(AuthSettings::refreshTtlDays() + 1)->days();

    refresh($signIn['session']['refreshToken'])->assertStatus(401)
        ->assertJsonPath('error.code', 'AUTH_SESSION_EXPIRED');
});

/**
 * Scenario G — the stolen phone.
 */
it('stops a revoked device from refreshing its way back in', function () {
    $signIn = signIn();

    Device::sole()->forceFill(['revoked_at' => now()])->save();

    refresh($signIn['session']['refreshToken'])->assertStatus(401)
        ->assertJsonPath('error.code', 'AUTH_DEVICE_REVOKED');
});

it('re-evaluates where to send the app on every refresh', function () {
    $signIn = signIn();

    expect(refresh($signIn['session']['refreshToken'])->json('data.nextStep'))->toBe('CREATE_PIN');
});
