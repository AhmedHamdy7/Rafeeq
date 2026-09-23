<?php

use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\OtpStatus;
use App\Domains\Identity\Models\AuthSession;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserConsent;
use App\Domains\Identity\Support\AuthSettings;
use Illuminate\Support\Facades\Hash;

/**
 * Chapter 2 §14 (one flow for register and sign-in), §23.2, scenarios A/B/E.
 */
beforeEach(function () {
    $this->sender = fakeOtpSender();
});

function requestCode(string $phone = '01012345678', string $devicePublicId = 'dev-1', string $purpose = 'authentication'): string
{
    return test()->postJson('/api/v1/auth/otp/request', [
        'phone' => $phone,
        'purpose' => $purpose,
        'device' => ['publicId' => $devicePublicId, 'platform' => 'android'],
    ])->assertStatus(202)->json('data.challengeId');
}

/**
 * Scenario A — a brand-new person completes registration.
 */
it('creates exactly one account, one device and one session for a new number', function () {
    $challengeId = requestCode();

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'challengeId' => $challengeId,
        'code' => $this->sender->lastCode(),
        'device' => ['publicId' => 'dev-1', 'platform' => 'android', 'appVersion' => '1.0.0'],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.accountState', 'NEW_USER')
        ->assertJsonPath('data.nextStep', 'CREATE_PIN')
        ->assertJsonPath('data.user.profileStatus', 'NOT_STARTED')
        ->assertJsonPath('data.pinLength', 4);

    expect(User::count())->toBe(1)
        ->and(Device::count())->toBe(1)
        ->and(AuthSession::whereNull('revoked_at')->count())->toBe(1)
        ->and(User::sole()->phone_verified_at)->not->toBeNull()
        // Consent records exist for every document required to use Rafeeq.
        ->and(UserConsent::count())->toBe(2)
        // No PIN material of any kind reaches the server.
        ->and(Device::sole()->has_local_pin)->toBeFalse();
});

it('signs the same number back in without ever creating a second account', function () {
    signIn();

    fakeOtpSender();
    $result = signIn(devicePublicId: 'dev-1');

    expect($result['accountState'])->toBe('EXISTING_USER')
        ->and(User::count())->toBe(1);
});

/**
 * Scenario B — reinstall. The app generates a new device id, which is what
 * makes "set a PIN again" fall out of the model rather than be special-cased.
 */
it('treats a reinstall as a new installation of an existing account', function () {
    signIn(devicePublicId: 'install-1');

    fakeOtpSender();
    $result = signIn(devicePublicId: 'install-2');

    expect($result['accountState'])->toBe('EXISTING_USER')
        ->and($result['nextStep'])->toBe('CREATE_PIN')
        ->and(User::count())->toBe(1)
        ->and(Device::count())->toBe(2);
});

it('returns the same response shape whether the account is new or returning', function () {
    $new = signIn(phone: '01012345678');

    fakeOtpSender();
    $returning = signIn(phone: '01012345678', devicePublicId: 'dev-1');

    expect(array_keys($new))->toBe(array_keys($returning));
});

it('rejects a wrong code and counts the attempt', function () {
    $challengeId = requestCode();

    $this->postJson('/api/v1/auth/otp/verify', [
        'challengeId' => $challengeId,
        'code' => '000000',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(401)->assertJsonPath('error.code', 'AUTH_OTP_INVALID');

    expect(OtpChallenge::findOrFail($challengeId)->attempt_count)->toBe(1)
        ->and(User::count())->toBe(0);
});

it('blocks the challenge once the attempts run out', function () {
    $challengeId = requestCode();

    foreach (range(1, AuthSettings::otpMaxAttempts()) as $ignored) {
        $this->postJson('/api/v1/auth/otp/verify', [
            'challengeId' => $challengeId,
            'code' => '000000',
            'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
        ]);
    }

    expect(OtpChallenge::findOrFail($challengeId)->status)->toBe(OtpStatus::Blocked);

    // The correct code no longer helps — the challenge itself is dead.
    $this->postJson('/api/v1/auth/otp/verify', [
        'challengeId' => $challengeId,
        'code' => $this->sender->lastCode(),
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(429)->assertJsonPath('error.code', 'AUTH_OTP_MAX_ATTEMPTS');

    expect(User::count())->toBe(0);
});

it('refuses a code whose lifetime has run out', function () {
    $challengeId = requestCode();
    $code = $this->sender->lastCode();

    $this->travel(AuthSettings::otpTtlSeconds() + 1)->seconds();

    $this->postJson('/api/v1/auth/otp/verify', [
        'challengeId' => $challengeId,
        'code' => $code,
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(401)->assertJsonPath('error.code', 'AUTH_OTP_EXPIRED');
});

it('never lets one code be spent twice', function () {
    $challengeId = requestCode();
    $code = $this->sender->lastCode();
    $device = ['publicId' => 'dev-1', 'platform' => 'android'];

    $this->postJson('/api/v1/auth/otp/verify', compact('challengeId', 'code', 'device'))->assertOk();

    $this->postJson('/api/v1/auth/otp/verify', compact('challengeId', 'code', 'device'))
        ->assertStatus(401)->assertJsonPath('error.code', 'AUTH_OTP_INVALID');

    expect(User::count())->toBe(1);
});

/**
 * Scenario E — the late SMS.
 */
it('accepts only the newest code after a resend', function () {
    requestCode();
    $oldCode = $this->sender->lastCode();

    $this->travel(AuthSettings::otpResendCooldownSeconds() + 1)->seconds();

    $newChallengeId = requestCode();
    $newCode = $this->sender->lastCode();
    $device = ['publicId' => 'dev-1', 'platform' => 'android'];

    // The old code cannot even be presented against the new challenge.
    $this->postJson('/api/v1/auth/otp/verify', [
        'challengeId' => $newChallengeId, 'code' => $oldCode, 'device' => $device,
    ])->assertStatus(401)->assertJsonPath('error.code', 'AUTH_OTP_INVALID');

    $this->postJson('/api/v1/auth/otp/verify', [
        'challengeId' => $newChallengeId, 'code' => $newCode, 'device' => $device,
    ])->assertOk();
})->skip(fn () => AuthSettings::otpMaxAttempts() < 2, 'needs at least two attempts');

/**
 * Pitfall #31 — a code proves one thing and authorises only that thing.
 */
it('refuses a challenge minted for a purpose this endpoint does not accept', function () {
    $challenge = OtpChallenge::factory()->create([
        'phone_e164' => '+201012345678',
        'purpose' => 'phone_change',
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(5),
        'status' => OtpStatus::Pending->value,
    ]);

    $this->postJson('/api/v1/auth/otp/verify', [
        'challengeId' => $challenge->id,
        'code' => '123456',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(404)->assertJsonPath('error.code', 'AUTH_OTP_CHALLENGE_NOT_FOUND');
});

/**
 * Scenario C — forgot PIN.
 */
it('clears the local PIN flag when a pin_reset code is verified', function () {
    signIn(devicePublicId: 'dev-1');

    Device::sole()->forceFill(['has_local_pin' => true, 'biometric_enabled' => true])->save();

    $sender = fakeOtpSender();
    $challengeId = requestCode(purpose: 'pin_reset');

    $result = $this->postJson('/api/v1/auth/otp/verify', [
        'challengeId' => $challengeId,
        'code' => $sender->lastCode(),
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertOk()->json('data');

    expect($result['nextStep'])->toBe('CREATE_PIN')
        ->and(Device::sole()->has_local_pin)->toBeFalse()
        ->and(Device::sole()->biometric_enabled)->toBeFalse();
});

/**
 * Scenario H — a suspended account signs in successfully and is routed away
 * from Home, rather than being locked out of its own appeal.
 */
it('signs a suspended account in but never routes it Home', function () {
    signIn(devicePublicId: 'dev-1');

    User::sole()->forceFill([
        'account_status' => AccountStatus::Suspended->value,
        'suspension_reason' => 'under_review',
    ])->save();

    fakeOtpSender();
    $result = signIn(devicePublicId: 'dev-1');

    expect($result['nextStep'])->toBe('ACCOUNT_SUSPENDED')
        ->and($result['user']['accountStatus'])->toBe('SUSPENDED')
        ->and($result['session']['accessToken'])->not->toBeEmpty();
});

it('never exposes gender, and the whole payload is free of it', function () {
    $result = signIn();

    expect($result['user'])->not->toHaveKey('gender')
        ->and(json_encode($result))->not->toContain('gender');
});
