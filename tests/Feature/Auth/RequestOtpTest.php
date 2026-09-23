<?php

use App\Domains\Identity\Enums\OtpStatus;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\AuthSettings;
use Illuminate\Support\Facades\Hash;

/**
 * Chapter 2 §11/§12/§23.1 and scenario D.
 */
beforeEach(function () {
    $this->sender = fakeOtpSender();
});

it('issues a challenge and returns only what the app needs to show the OTP screen', function () {
    $response = $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => [
            'challengeId', 'expiresInSeconds', 'resendAvailableInSeconds', 'maskedPhone',
        ]]);

    expect($response->json('data.maskedPhone'))->toBe('+20 10 *** 5678');
});

it('never returns the code, and never stores it in the clear', function () {
    $response = $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ]);

    $code = $this->sender->lastCode();
    $challenge = OtpChallenge::findOrFail($response->json('data.challengeId'));

    expect($response->getContent())->not->toContain($code)
        ->and($challenge->code_hash)->not->toBe($code)
        ->and(Hash::check($code, $challenge->code_hash))->toBeTrue()
        // #[Hidden] keeps the hash out of any accidental serialization too.
        ->and($challenge->toArray())->not->toHaveKey('code_hash');
});

it('generates a code of exactly the configured length, digits only', function () {
    $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(202);

    expect($this->sender->lastCode())->toMatch('/^\d{'.AuthSettings::otpLength().'}$/');
});

it('normalises every way an Egyptian number can be typed to one identity', function (string $typed) {
    $this->postJson('/api/v1/auth/otp/request', [
        'phone' => $typed,
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(202);

    expect(OtpChallenge::latest('id')->first()->phone_e164)->toBe('+201012345678');
})->with([
    '01012345678',
    '+201012345678',
    '00201012345678',
    '0020 10 1234 5678',
    '٠١٠١٢٣٤٥٦٧٨',        // Arabic-Indic digits — pitfall #62
    '۰۱۰۱۲۳۴۵۶۷۸',        // Eastern Arabic-Indic
]);

it('rejects a malformed number as a validation error, without sending anything', function () {
    $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '0123456',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    expect($this->sender->sent)->toBeEmpty();
});

/**
 * Scenario D — the property the whole endpoint exists to protect.
 */
it('answers identically whether or not the number has an account', function () {
    User::factory()->create(['phone_e164' => '+201012345678']);

    $existing = $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ]);

    fakeOtpSender();

    $unknown = $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01112223344',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ]);

    // Same status, same keys, and the only differing values are the ones
    // derived from the number the caller already typed.
    expect($existing->status())->toBe($unknown->status())
        ->and(array_keys($existing->json('data')))->toBe(array_keys($unknown->json('data')))
        ->and($existing->json('data.expiresInSeconds'))->toBe($unknown->json('data.expiresInSeconds'))
        ->and($existing->json('data.resendAvailableInSeconds'))->toBe($unknown->json('data.resendAvailableInSeconds'));
});

/**
 * Scenario E — a late-arriving SMS must be useless.
 */
it('expires the previous challenge when a new code is issued', function () {
    $first = $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->json('data.challengeId');

    $this->travel(AuthSettings::otpResendCooldownSeconds() + 1)->seconds();

    $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(202);

    expect(OtpChallenge::findOrFail($first)->status)->toBe(OtpStatus::Expired);
});

it('refuses a resend during the cooldown and says when to come back', function () {
    $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(202);

    $response = $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ]);

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'AUTH_OTP_RESEND_COOLDOWN')
        ->assertHeader('Retry-After');

    expect(count($this->sender->sent))->toBe(1);
});

it('caps resends within one live challenge window', function () {
    // The first request is the original; each later one inside the same live
    // window is a resend, so the cap bites on request (max_resends + 2).
    for ($i = 0; $i <= AuthSettings::otpMaxResends(); $i++) {
        $this->postJson('/api/v1/auth/otp/request', [
            'phone' => '01012345678',
            'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
        ])->assertStatus(202);

        $this->travel(AuthSettings::otpResendCooldownSeconds() + 1)->seconds();
    }

    $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(429)->assertJsonPath('error.code', 'AUTH_OTP_MAX_RESENDS');
});

it('forgives the resend chain once the live code actually expires', function () {
    // More requests than the resend cap allows inside one window — but each
    // separated by more than the code's lifetime, so none of them is a
    // resend and the chain starts over every time.
    foreach (range(1, AuthSettings::otpMaxResends() + 2) as $ignored) {
        $this->postJson('/api/v1/auth/otp/request', [
            'phone' => '01012345678',
            'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
        ])->assertStatus(202);

        $this->travel(AuthSettings::otpTtlSeconds() + 1)->seconds();
    }

    expect(count($this->sender->sent))->toBe(AuthSettings::otpMaxResends() + 2);
});

it('caps how many codes one number can pull in an hour', function () {
    $limit = AuthSettings::otpRequestsPerPhonePerHour();

    for ($i = 0; $i < $limit; $i++) {
        $this->postJson('/api/v1/auth/otp/request', [
            'phone' => '01012345678',
            'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
        ])->assertStatus(202);

        // Let each code die of old age, so the per-window resend cap never
        // fires and the hourly budget is what is actually under test.
        $this->travel(AuthSettings::otpTtlSeconds() + 1)->seconds();
    }

    $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
});

/**
 * Scenario I — never claim a code was sent when it was not.
 */
it('withdraws the challenge when the provider fails, instead of claiming success', function () {
    $this->sender->shouldFail = true;

    $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(503)->assertJsonPath('error.code', 'AUTH_OTP_DELIVERY_FAILED');

    expect(OtpChallenge::where('status', OtpStatus::Pending->value)->count())->toBe(0);
});

it('audits the request without writing the code or the full number', function () {
    $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(202);

    $event = SecurityEvent::where('event_type', SecurityEventType::OtpRequested->value)->sole();

    // +201012345678 → everything but the last four digits is masked.
    expect($event->metadata['phone'])->toBe('*********5678')
        ->and(json_encode($event->metadata))->not->toContain($this->sender->lastCode());
});

it('refuses to mint a code for a purpose only an authenticated caller may spend', function () {
    $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '01012345678',
        'purpose' => 'phone_change',
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ])->assertStatus(422);

    expect($this->sender->sent)->toBeEmpty();
});
