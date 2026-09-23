<?php

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Identity\Support\AuthSettings;
use App\Domains\Identity\Support\ProfileSettings;

/**
 * Binding standard #11: every policy number reaches the code through
 * `platform_settings`, so the admin dashboard can retune the platform
 * without a release.
 *
 * These tests exist because the promise is only real if changing a row
 * actually changes behaviour — a settings accessor that silently keeps
 * reading `config` would look identical from the outside.
 */
function setSetting(string $key, mixed $value): void
{
    PlatformSetting::updateOrCreate(
        ['setting_key' => $key],
        ['setting_value' => $value, 'value_type' => 'integer'],
    );
}

it('takes the minimum age from platform settings, not from the shipped default', function () {
    expect(ProfileSettings::minimumAgeYears())->toBe(18);

    setSetting('profile.minimum_age_years', 21);

    expect(ProfileSettings::minimumAgeYears())->toBe(21);
});

it('enforces the tuned minimum age at the endpoint', function () {
    $signIn = signIn();

    setSetting('profile.minimum_age_years', 21);

    $payload = [
        'fullName' => 'مريم حسن',
        'gender' => 'woman',
        'registeredRole' => 'passenger',
        'preferredLanguage' => 'ar',
    ];

    // Nineteen: fine under the shipped default of 18, refused under 21.
    $this->withToken($signIn['session']['accessToken'])
        ->putJson('/api/v1/account/profile/basic', [
            ...$payload,
            'dateOfBirth' => now()->subYears(19)->toDateString(),
        ])
        ->assertStatus(422)
        // The message quotes the tuned number, so the person is told the
        // rule that actually applied to them.
        ->assertJsonPath('error.fields.dateOfBirth.0', __('validation.custom.date_of_birth.minimum_age', ['age' => 21]));

    $this->withToken($signIn['session']['accessToken'])
        ->putJson('/api/v1/account/profile/basic', [
            ...$payload,
            'dateOfBirth' => now()->subYears(22)->toDateString(),
        ])
        ->assertOk();
});

it('takes the name length bounds from platform settings', function () {
    $signIn = signIn();

    setSetting('profile.full_name_max_length', 8);

    expect(ProfileSettings::fullNameMaxLength())->toBe(8);

    $this->withToken($signIn['session']['accessToken'])
        ->putJson('/api/v1/account/profile/basic', [
            'fullName' => 'مريم حسن عبد الله',
            'gender' => 'woman',
            'registeredRole' => 'passenger',
        ])
        ->assertStatus(422);
});

it('takes the OTP and session numbers from platform settings', function (string $key, callable $read, int $tuned) {
    setSetting($key, $tuned);

    expect($read())->toBe($tuned);
})->with([
    ['auth.otp.ttl_seconds', fn () => AuthSettings::otpTtlSeconds(), 300],
    ['auth.otp.max_attempts', fn () => AuthSettings::otpMaxAttempts(), 3],
    ['auth.otp.resend_cooldown_seconds', fn () => AuthSettings::otpResendCooldownSeconds(), 60],
    ['auth.session.access_ttl_minutes', fn () => AuthSettings::accessTtlMinutes(), 5],
    ['auth.session.refresh_ttl_days', fn () => AuthSettings::refreshTtlDays(), 30],
    ['auth.rate_limits.otp_requests_per_phone_per_hour', fn () => AuthSettings::otpRequestsPerPhonePerHour(), 2],
]);

it('applies a tuned OTP lifetime to a real challenge', function () {
    $sender = fakeOtpSender();

    setSetting('auth.otp.ttl_seconds', 300);

    $response = $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(202);

    expect($response->json('data.expiresInSeconds'))->toBe(300);

    // Past the shipped 120s default, still inside the tuned 300s window.
    $this->travel(200)->seconds();

    $this->postJson('/api/v1/auth/otp/verify', [
        'challengeId' => $response->json('data.challengeId'),
        'code' => $sender->lastCode(),
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertOk();
});

it('keeps the OTP transport out of runtime settings', function () {
    // DEPLOY-ONLY: a mistyped transport in a dashboard would take
    // authentication down for everyone, so it is read from config alone and
    // a platform_settings row must have no effect.
    setSetting('auth.otp.driver', 'nonsense');

    expect(config('rafeeq.auth.otp.driver'))->toBe('log');

    fakeOtpSender();

    $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(202);
});
