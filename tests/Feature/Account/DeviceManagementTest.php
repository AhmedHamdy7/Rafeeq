<?php

use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\AuthSession;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Identity\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Chapter 2 §21, §23.4, §23.5 and scenario G.
 */
it('lists the account devices and marks which one is being used', function () {
    $first = signIn(devicePublicId: 'dev-1');

    fakeOtpSender();
    signIn(devicePublicId: 'dev-2');

    // Sign in again on the first device so its token is current.
    fakeOtpSender();
    $current = signIn(devicePublicId: 'dev-1');

    $devices = $this->withToken($current['session']['accessToken'])
        ->getJson('/api/v1/account/devices')->assertOk()->json('data');

    expect($devices)->toHaveCount(2)
        ->and(collect($devices)->firstWhere('isCurrent', true)['id'])->toBe($current['device']['id'])
        // A live push credential is never rendered back.
        ->and(json_encode($devices))->not->toContain('pushToken');
});

it('signs out only the current device on logout, and kills its access token too', function () {
    $signIn = signIn();
    $token = $signIn['session']['accessToken'];

    $this->withToken($token)->postJson('/api/v1/auth/logout')
        ->assertOk()->assertJsonPath('data.revoked', true);

    // The access token is gone, not merely marked — so it cannot outlive the
    // session for the rest of its 15 minutes.
    expect(PersonalAccessToken::count())->toBe(0)
        ->and(AuthSession::sole()->revoked_at)->not->toBeNull()
        ->and(Device::sole()->push_token)->toBeNull()
        // The account itself survives (§23.4).
        ->and(User::count())->toBe(1);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

/**
 * Scenario G — revoking the stolen phone from another device.
 */
it('revokes another device without disturbing the one doing the revoking', function () {
    $stolen = signIn(devicePublicId: 'stolen-phone');

    fakeOtpSender();
    $safe = signIn(devicePublicId: 'laptop');

    $this->withToken($safe['session']['accessToken'])
        ->deleteJson("/api/v1/account/devices/{$stolen['device']['id']}/session")
        ->assertOk();

    // The stolen device is locked out of both halves of its session.
    $this->withToken($stolen['session']['accessToken'])->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->postJson('/api/v1/auth/session/refresh', [
        'refreshToken' => $stolen['session']['refreshToken'],
    ])->assertStatus(401)->assertJsonPath('error.code', 'AUTH_DEVICE_REVOKED');

    // The device in hand keeps working.
    $this->withToken($safe['session']['accessToken'])->getJson('/api/v1/auth/me')->assertOk();

    expect(SecurityEvent::where('event_type', SecurityEventType::DeviceRevoked->value)->count())->toBe(1);
});

/**
 * IDOR (Bible §6): someone else's device id must be indistinguishable from
 * one that does not exist.
 */
it('answers 404, not 403, for a device belonging to another account', function () {
    $mine = signIn(phone: '01012345678', devicePublicId: 'mine');

    fakeOtpSender();
    $theirs = signIn(phone: '01112223344', devicePublicId: 'theirs');

    $this->withToken($mine['session']['accessToken'])
        ->deleteJson("/api/v1/account/devices/{$theirs['device']['id']}/session")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect(Device::findOrFail($theirs['device']['id'])->revoked_at)->toBeNull();
});

it('records that a local PIN exists without ever receiving one', function () {
    $signIn = signIn();

    $device = $this->withToken($signIn['session']['accessToken'])
        ->patchJson('/api/v1/account/devices/current/security', [
            'hasLocalPin' => true,
            'biometricEnabled' => true,
        ])->assertOk()->json('data');

    expect($device['hasLocalPin'])->toBeTrue()
        ->and($device['isTrusted'])->toBeTrue()
        ->and(SecurityEvent::where('event_type', SecurityEventType::LocalPinSet->value)->count())->toBe(1);

    // Nothing resembling a PIN value exists anywhere in the devices table.
    expect(array_keys(Device::sole()->getAttributes()))
        ->not->toContain('pin', 'pin_hash', 'pin_verifier');
});

it('routes a returning device with a PIN straight past the PIN setup step', function () {
    $signIn = signIn(devicePublicId: 'dev-1');

    $this->withToken($signIn['session']['accessToken'])
        ->patchJson('/api/v1/account/devices/current/security', ['hasLocalPin' => true])
        ->assertOk();

    fakeOtpSender();
    $again = signIn(devicePublicId: 'dev-1');

    // Profile is still unfinished, so that — not PIN setup — is what is left.
    expect($again['nextStep'])->toBe('COMPLETE_PROFILE');
});

it('requires authentication for every account route', function (string $method, string $uri) {
    $this->json($method, $uri)->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
})->with([
    ['GET', '/api/v1/account/devices'],
    ['PATCH', '/api/v1/account/devices/current/security'],
    ['GET', '/api/v1/account/consents'],
    ['PUT', '/api/v1/account/profile/basic'],
    ['GET', '/api/v1/auth/me'],
    ['POST', '/api/v1/auth/logout'],
]);
