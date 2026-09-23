<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Verification\Actions\ReviewVerificationAction;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
 * Minimal profile setup, which the verification endpoints require: a reviewer
 * compares the name on a document against the account.
 *
 * NOTE for callers: `withToken()` sets a DEFAULT header on the test case, so
 * every helper here leaves the test authenticated for subsequent requests. A
 * test that needs an anonymous request must say `withoutToken()` explicitly.
 */
function completeBasicProfile(string $accessToken): void
{
    test()->withToken($accessToken)->putJson('/api/v1/account/profile/basic', [
        'fullName' => 'مريم حسن',
        'gender' => 'woman',
        'registeredRole' => 'passenger',
        'dateOfBirth' => '1996-04-12',
    ])->assertOk();
}

/**
 * Uploads one document towards one verification level. The fake image is a
 * REAL image produced by GD — the upload path scans the actual bytes and
 * re-encodes them, so a placeholder string would be refused, as it should be.
 */
function uploadVerificationDocument(string $accessToken, string $type, string $kind, ?UploadedFile $file = null)
{
    return test()->withToken($accessToken)
        ->postJson("/api/v1/account/verifications/{$type}/documents", [
            'kind' => $kind,
            'file' => $file ?? UploadedFile::fake()->image("{$kind}.jpg", 1200, 800),
        ]);
}

/**
 * Takes a government-ID level all the way to "under review": complete the
 * profile, upload both sides, submit.
 */
function submitGovernmentId(string $accessToken): void
{
    completeBasicProfile($accessToken);

    uploadVerificationDocument($accessToken, 'government_id', 'national_id_front')->assertStatus(201);
    uploadVerificationDocument($accessToken, 'government_id', 'national_id_back')->assertStatus(201);

    test()->withToken($accessToken)
        ->postJson('/api/v1/account/verifications/government_id/submit')
        ->assertOk();
}

/**
 * A reviewer's decision. The HTTP surface for this belongs to the admin
 * dashboard (Phase 13), so tests drive the Action the way that dashboard will.
 *
 * Scoped to the verification currently AWAITING a decision rather than to the
 * only one of its type: a test with two accounts has two rows of the same type,
 * and `sole()` on the type alone would fail — or worse, act on the wrong
 * person's.
 */
function pendingVerification(VerificationType $type): UserVerification
{
    return UserVerification::query()
        ->where('type', $type->value)
        ->where('status', VerificationStatus::Pending->value)
        ->sole();
}

function approveVerification(VerificationType $type, ?DateTimeInterface $expiresAt = null): void
{
    app(ReviewVerificationAction::class)->approve(
        pendingVerification($type),
        AdminUser::factory()->create(),
        $expiresAt,
    );
}

function rejectVerification(VerificationType $type, string $reason): void
{
    app(ReviewVerificationAction::class)->reject(
        pendingVerification($type),
        AdminUser::factory()->create(),
        $reason,
    );
}

/**
 * Everything a driver application needs before it can be submitted: a verified
 * identity (the gate on every /v1/driver route), a date of birth, licence
 * details, both licence images and a vehicle with its registration.
 *
 * Returns the access token, since the caller usually needs to keep going.
 */
function readyDriverApplicant(string $phone = '01012345678', string $devicePublicId = 'dev-1'): string
{
    // The phone IS the identity, so a test needing a second applicant must
    // pass a different one — otherwise `signIn()` returns the same account and
    // the "duplicate detected" tests would be comparing someone with themself.
    $token = signIn(phone: $phone, devicePublicId: $devicePublicId)['session']['accessToken'];

    completeBasicProfile($token);
    submitGovernmentId($token);
    approveVerification(VerificationType::GovernmentId);

    test()->withToken($token)->postJson('/api/v1/driver/application')->assertStatus(201);

    return $token;
}

/**
 * Licence details, both images, a vehicle and its registration — the whole
 * application short of pressing submit.
 */
function completeDriverApplication(string $token): void
{
    test()->withToken($token)->putJson('/api/v1/driver/application/licence', [
        'nationalId' => '29604120101234',
        'licenceNumber' => 'DL-9931204',
        'licenceExpiry' => now()->addYears(3)->toDateString(),
    ])->assertOk();

    uploadVerificationDocument($token, 'driving_licence', 'licence_front')->assertStatus(201);
    uploadVerificationDocument($token, 'driving_licence', 'licence_back')->assertStatus(201);

    $vehicle = test()->withToken($token)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Toyota',
        'model' => 'Corolla',
        'year' => 2019,
        'colour' => 'Silver',
        'plateNumber' => 'ABC 1234',
        'seats' => 5,
        'fuelType' => 'petrol',
    ])->assertStatus(201)->json('data.id');

    test()->withToken($token)->postJson("/api/v1/driver/vehicles/{$vehicle}/documents", [
        'type' => 'registration',
        'file' => UploadedFile::fake()->image('registration.jpg', 1000, 700),
    ])->assertStatus(201);
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
