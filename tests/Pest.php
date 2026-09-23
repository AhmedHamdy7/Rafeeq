<?php

use App\Domains\Identity\Contracts\OtpSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeOtpSender;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Swaps in the capturing OTP transport and hands it back, so a test can
 * read the code that was "texted". Also clears the rate limiter, which is
 * cache-backed and therefore survives the database refresh between tests —
 * without this, a file of OTP tests would start failing partway through for
 * reasons that have nothing to do with what is under test.
 */
function fakeOtpSender(): FakeOtpSender
{
    Cache::clear();

    $sender = new FakeOtpSender;

    app()->instance(OtpSender::class, $sender);

    return $sender;
}

/**
 * Drives the real endpoints end to end: request a code, read it from the
 * fake transport, verify it. Returns the decoded `data` payload, so tests
 * assert against exactly what the app would receive.
 *
 * @return array<string, mixed>
 */
function signIn(string $phone = '01012345678', string $devicePublicId = 'device-under-test', array $overrides = []): array
{
    $sender = $overrides['sender'] ?? fakeOtpSender();

    $device = array_merge([
        'publicId' => $devicePublicId,
        'platform' => 'android',
        'appVersion' => '1.0.0',
    ], $overrides['device'] ?? []);

    $challenge = test()->postJson('/api/v1/auth/otp/request', [
        'phone' => $phone,
        'purpose' => $overrides['purpose'] ?? 'authentication',
        'device' => $device,
    ])->assertStatus(202);

    return test()->postJson('/api/v1/auth/otp/verify', [
        'challengeId' => $challenge->json('data.challengeId'),
        'code' => $sender->lastCode(),
        'device' => $device,
    ])->assertOk()->json('data');
}
