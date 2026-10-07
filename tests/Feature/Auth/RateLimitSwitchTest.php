<?php

use App\Domains\Shared\Support\RateLimits;

/**
 * `RAFEEQ_RATE_LIMITS=off` — a test environment where the team signs in all day.
 *
 * 🔒 The half that matters most is the last test: production ignores the switch, so a forgotten
 * variable cannot turn the platform into an unlimited SMS sender or a free code-guessing service.
 */
beforeEach(function () {
    fakeOtpSender();
});

function askForCode(string $phone = '01012345678')
{
    return test()->postJson('/api/v1/auth/otp/request', [
        'phone' => $phone,
        'device' => ['publicId' => 'dev-1', 'platform' => 'android'],
    ]);
}

it('lets a test environment request and resend codes without limit when switched off', function () {
    config(['rafeeq.rate_limits_enabled' => false]);

    // Far past the per-phone hourly limit, the resend cooldown and the resend cap.
    foreach (range(1, 25) as $attempt) {
        askForCode()->assertStatus(202);
    }
});

it('keeps every limit by default', function () {
    askForCode()->assertStatus(202);

    // An immediate second request is a resend inside the cooldown.
    askForCode()->assertStatus(429);
});

it('ignores the switch in production', function () {
    config(['rafeeq.rate_limits_enabled' => false]);
    app()->detectEnvironment(fn () => 'production');

    expect(RateLimits::enabled())->toBeTrue();
});
