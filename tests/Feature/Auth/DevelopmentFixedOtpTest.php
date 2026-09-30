<?php

use App\Domains\Identity\Models\OtpChallenge;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * A fixed OTP code, so a shared server with no SMS provider can be signed into.
 *
 * 🔴 Why this exists: the only transport writes the code to a log, and an external team
 * working against a deployed instance cannot read a log. Without it they reach the OTP screen
 * and stop — so the real choice is between a fixed code on a staging box and the mobile team
 * being blocked entirely.
 *
 * 🔒 And why it is safe to have in the codebase at all: the guard is on the ENVIRONMENT, not on
 * the config value, and in production it THROWS rather than quietly ignoring the setting. Those
 * two properties are what the tests below are really about — the feature itself is four lines.
 */
beforeEach(function () {
    $this->sender = fakeOtpSender();
});

/**
 * NOT `requestOtpResponse()` — `VerifyOtpTest` already declares one, and a global function declared in
 * a test file collides fatally with the same name in another. That one returns the challenge id;
 * these tests need the whole response, to assert 202, 429 and 500.
 */
function requestOtpResponse(string $phone = '01012345678')
{
    return test()->postJson('/api/v1/auth/otp/request', [
        'phone' => $phone,
        'device' => ['publicId' => 'dev-fixed-1', 'platform' => 'android'],
    ]);
}

it('issues the fixed code when one is configured', function () {
    config(['rafeeq.auth.otp.dev_fixed_code' => '123456']);

    requestOtpResponse()->assertStatus(202);

    // Compared against the HASH, because the code is never stored or returned in the clear.
    expect(Hash::check('123456', OtpChallenge::sole()->code_hash))->toBeTrue();
});

it('issues the same code every time, which is the whole point', function () {
    config(['rafeeq.auth.otp.dev_fixed_code' => '123456']);

    requestOtpResponse('01012345678')->assertStatus(202);
    requestOtpResponse('01112223344')->assertStatus(202);

    foreach (OtpChallenge::all() as $challenge) {
        expect(Hash::check('123456', $challenge->code_hash))->toBeTrue();
    }
});

it('lets the fixed code actually sign somebody in', function () {
    config(['rafeeq.auth.otp.dev_fixed_code' => '123456']);

    $challengeId = requestOtpResponse()->assertStatus(202)->json('data.challengeId');

    // The end the feature exists for: the mobile team types 123456 and gets a token.
    test()->postJson('/api/v1/auth/otp/verify', [
        'challengeId' => $challengeId,
        'code' => '123456',
        'device' => ['publicId' => 'dev-fixed-1', 'platform' => 'android'],
    ])->assertOk()
        ->assertJsonPath('data.session.accessToken', fn ($token) => is_string($token) && $token !== '');
});

it('goes back to random codes when it is not configured', function () {
    config(['rafeeq.auth.otp.dev_fixed_code' => null]);

    requestOtpResponse('01012345678')->assertStatus(202);
    requestOtpResponse('01112223344')->assertStatus(202);

    $hashes = OtpChallenge::all();

    // Two random six-digit codes colliding is a 1-in-a-million coincidence; the same fixed code
    // twice is a certainty. So: neither challenge accepts the other's obvious candidate.
    expect($hashes)->toHaveCount(2)
        ->and(Hash::check('123456', $hashes[0]->code_hash))->toBeFalse()
        ->and(Hash::check('123456', $hashes[1]->code_hash))->toBeFalse();
});

it('treats an empty string as not configured', function () {
    // An unset env var reads as '' rather than null through some layers, and an empty fixed code
    // would otherwise be hashed and issued as a code nobody could ever type.
    config(['rafeeq.auth.otp.dev_fixed_code' => '']);

    requestOtpResponse()->assertStatus(202);

    expect(Hash::check('', OtpChallenge::sole()->code_hash))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The guards, which are the actual feature
|--------------------------------------------------------------------------
*/

/**
 * 🔒 The most important test in this file.
 *
 * A fixed OTP reaching production means every account on the platform is signable into by
 * anybody who read the repository. So the refusal is ABSOLUTE rather than "ignore it in
 * production" — because quietly doing the safe thing is exactly how it stays configured, and
 * then one day the environment name changes and nobody remembers why it mattered.
 */
it('refuses outright in production rather than quietly ignoring it', function () {
    config(['rafeeq.auth.otp.dev_fixed_code' => '123456']);
    app()->detectEnvironment(fn () => 'production');

    requestOtpResponse()->assertStatus(500);

    // And nothing was issued: no challenge, so no code, so no way in.
    expect(OtpChallenge::count())->toBe(0);
});

/**
 * 🔒 The guard reads the ENVIRONMENT, not the config value — the same reasoning as
 * `LogOtpSender` refusing to run in production. Config is exactly what a wrong deploy gets
 * wrong, so a `RAFEEQ_DEV_OTP_CODE` left in a production `.env` by mistake must be inert.
 */
it('is decided by the environment, not by any config flag', function () {
    config([
        'rafeeq.auth.otp.dev_fixed_code' => '123456',
        // Whatever else a misconfigured deploy might have set, in any combination.
        'app.debug' => true,
        'app.env' => 'local',
    ]);

    app()->detectEnvironment(fn () => 'production');

    // `app.env` says local and debug is on, and it still refuses: only the resolved
    // environment counts.
    requestOtpResponse()->assertStatus(500);
});

/**
 * 🔴 A wrong length would otherwise present as "nobody can sign in and there is no error
 * anywhere": the code is issued fine, and verification then rejects it because the request rule
 * demands exactly `auth.otp.length` digits. Failing loudly at the point of use is the only way
 * that is debuggable.
 */
it('refuses a fixed code of the wrong length', function () {
    config(['rafeeq.auth.otp.dev_fixed_code' => '1234']);

    requestOtpResponse()->assertStatus(500);

    expect(OtpChallenge::count())->toBe(0);
});

it('refuses a fixed code that is not digits', function () {
    config(['rafeeq.auth.otp.dev_fixed_code' => 'abcdef']);

    requestOtpResponse()->assertStatus(500);
});

it('follows the configured OTP length rather than assuming six', function () {
    config([
        'rafeeq.auth.otp.length' => 4,
        'rafeeq.auth.otp.dev_fixed_code' => '1234',
    ]);

    requestOtpResponse()->assertStatus(202);

    expect(Hash::check('1234', OtpChallenge::sole()->code_hash))->toBeTrue();
});

/**
 * A server where every code is the same is a server anybody can sign into as anybody. That fact
 * should be impossible to miss while reading its logs.
 */
it('says so in the log every time it is used', function () {
    config(['rafeeq.auth.otp.dev_fixed_code' => '123456']);

    Log::spy();

    requestOtpResponse()->assertStatus(202);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'fixed OTP'));
});

/**
 * 🔒 It changes how the code is CHOSEN and nothing else. Every other protection — the rate
 * limits, the resend cooldown, the attempt cap, the identical answer for a number with and
 * without an account — is untouched, because a fixed code on a shared server must not also be
 * an unthrottled one.
 */
it('changes nothing else about how codes are issued', function () {
    config(['rafeeq.auth.otp.dev_fixed_code' => '123456']);

    $first = requestOtpResponse()->assertStatus(202);

    // The resend cooldown still applies.
    requestOtpResponse()->assertStatus(429)->assertHeader('Retry-After');

    // And the response still says nothing about whether the number has an account.
    expect($first->json('data'))->toHaveKeys([
        'challengeId', 'expiresInSeconds', 'resendAvailableInSeconds', 'maskedPhone',
    ])->and($first->json('data.challengeId'))->not->toBeEmpty();
});

it('still hashes the code rather than storing it', function () {
    config(['rafeeq.auth.otp.dev_fixed_code' => '123456']);

    requestOtpResponse()->assertStatus(202);

    // 🔒 Chapter 2 §23.1: the response carries a challenge id only, and the column is a hash.
    expect(OtpChallenge::sole()->code_hash)->not->toBe('123456')
        ->and(OtpChallenge::sole()->getAttributes())->not->toHaveKey('code');
});
