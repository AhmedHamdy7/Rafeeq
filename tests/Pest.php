<?php

use App\Domains\Admin\Actions\SyncAdminRolesAction;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Verification\Actions\ReviewVerificationAction;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use App\Http\Middleware\EnsureAdminMfaIsConfirmed;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;
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
    /*
     * Only if the profile is not already complete.
     *
     * `completeBasicProfile()` hard-codes `gender => woman`, so calling it
     * unconditionally here OVERWROTE the gender of anyone set up differently. That
     * silently turned a test's man into a woman before he requested a seat, which
     * made a women-only security test exercise nothing at all — the exact shape of
     * failure the rest of this suite exists to prevent.
     */
    $complete = test()->withToken($accessToken)->getJson('/api/v1/auth/me')
        ->json('data.user.profileStatus') === 'BASIC_COMPLETE';

    if (! $complete) {
        completeBasicProfile($accessToken);
    }

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

function askForVerificationInfo(VerificationType $type, string $reason): void
{
    app(ReviewVerificationAction::class)->requestMoreInfo(
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
 *
 * `$seed` varies the national id, licence number and plate. A test with two
 * applicants MUST pass a different one: those three are unique platform-wide, so
 * a second driver reusing them is refused as a duplicate, which is the system
 * working correctly and the test setup being wrong.
 */
function completeDriverApplication(string $token, int $seed = 1): void
{
    test()->withToken($token)->putJson('/api/v1/driver/application/licence', [
        'nationalId' => '2960412010'.str_pad((string) $seed, 4, '0', STR_PAD_LEFT),
        'licenceNumber' => 'DL-993120'.$seed,
        'licenceExpiry' => now()->addYears(3)->toDateString(),
    ])->assertOk();

    uploadVerificationDocument($token, 'driving_licence', 'licence_front')->assertStatus(201);
    uploadVerificationDocument($token, 'driving_licence', 'licence_back')->assertStatus(201);

    $vehicle = test()->withToken($token)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Toyota',
        'model' => 'Corolla',
        'year' => 2019,
        'colour' => 'Silver',
        'plateNumber' => 'ABC '.(1234 + $seed),
        'seats' => 5,
        'fuelType' => 'petrol',
    ])->assertStatus(201)->json('data.id');

    test()->withToken($token)->postJson("/api/v1/driver/vehicles/{$vehicle}/documents", [
        'type' => 'registration',
        'file' => UploadedFile::fake()->image('registration.jpg', 1000, 700),
    ])->assertStatus(201);
}

/**
 * A driver who has been through the whole of Chapter 3: application submitted,
 * reviewed, approved, with one approved and active vehicle. This is the state
 * Chapter 4 opens by assuming.
 *
 * Returns the access token.
 */
function approvedDriver(string $phone = '01012345678', string $devicePublicId = 'dev-1', int $seed = 1): string
{
    $token = readyDriverApplicant($phone, $devicePublicId);

    completeDriverApplication($token, $seed);

    test()->withToken($token)->postJson('/api/v1/driver/application/submit')->assertOk();

    // Scoped to the application awaiting a decision, so a test with two drivers
    // approves the right one rather than failing on an ambiguous `sole()`.
    app(ReviewDriverApplicationAction::class)->approve(
        DriverProfile::query()->where('status', DriverProfileStatus::PendingReview->value)->sole(),
        AdminUser::factory()->create(),
    );

    return $token;
}

/*
 * Commute helpers. They live here rather than in one test file because more than
 * one file needs them, and a global function declared in a test file only exists
 * when THAT file is collected — running a single file would otherwise fail on a
 * helper it can see in the editor.
 *
 * The reference journey is the Bible's: Rehab City to Smart Village, Sunday to
 * Thursday, 07:05.
 */

function createCommute(string $token, string $vehicleId, array $overrides = [])
{
    return test()->withToken($token)->postJson('/api/v1/commutes', array_merge([
        'commuteType' => 'recurring',
        'vehicleId' => $vehicleId,
        'direction' => 'to_work',
        'seatsTotal' => 3,
        'pricePerSeatPiastres' => 8000,
        'maxDetourMinutes' => 10,
        'maxWalkMinutes' => 15,
        'audience' => 'women_only',
    ], $overrides));
}

function saveRoute(string $token, string $commuteId, array $overrides = [])
{
    return test()->withToken($token)->putJson("/api/v1/commutes/{$commuteId}/route", array_merge([
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913, 'address' => 'Rehab Gate 2'],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716, 'address' => 'Smart Village B6'],
    ], $overrides));
}

function saveSchedule(string $token, string $commuteId, array $overrides = [])
{
    return test()->withToken($token)->putJson("/api/v1/commutes/{$commuteId}/schedule", array_merge([
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'departureTime' => '07:05:00',
        'startDate' => CarbonImmutable::tomorrow()->toDateString(),
        'endDate' => CarbonImmutable::today()->addMonths(4)->toDateString(),
    ], $overrides));
}

/**
 * A passenger with a complete profile, signed in. `gender` matters: it is what
 * the hard audience filter reads, and it is read from the ACCOUNT, never from a
 * search request.
 */
function passenger(string $phone, string $gender = 'woman', string $device = 'pax'): string
{
    fakeOtpSender();

    $token = signIn(phone: $phone, devicePublicId: $device)['session']['accessToken'];

    test()->withToken($token)->putJson('/api/v1/account/profile/basic', [
        'fullName' => 'سارة محمود',
        'gender' => $gender,
        'registeredRole' => 'passenger',
        'dateOfBirth' => '1995-03-10',
    ])->assertOk();

    return $token;
}

/**
 * A passenger who has also had their identity verified — what asking for a seat
 * requires, unlike searching. Getting into a stranger's car is a higher bar than
 * browsing.
 */
function verifiedPassenger(string $phone, string $gender = 'woman', string $device = 'pax'): string
{
    $token = passenger($phone, $gender, $device);

    submitGovernmentId($token);
    approveVerification(VerificationType::GovernmentId);

    return $token;
}

/**
 * The Bible's reference journey as a set of search criteria — Rehab to Smart Village,
 * Sunday to Thursday, arriving between 06:45 and 08:00.
 *
 * Its own helper because three endpoints take this same shape: the search itself, a
 * saved search, and a saved commute request.
 *
 * @return array<string, mixed>
 */
function searchCriteria(array $overrides = []): array
{
    return array_merge([
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716],
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'arrivalWindowStart' => '06:45:00',
        'arrivalWindowEnd' => '08:00:00',
        'maxWalkMinutes' => 15,
        'maxDetourMinutes' => 15,
    ], $overrides);
}

/**
 * Searches for the reference journey.
 */
function search(string $token, array $overrides = [])
{
    return test()->withToken($token)
        ->getJson('/api/v1/search/commutes?'.http_build_query(searchCriteria($overrides)));
}

/**
 * A draft with everything filled in — one step short of pressing publish.
 */
/**
 * A commute with a route and a schedule, ready to publish.
 *
 * `$overrides` reaches the commute itself (seats, price, audience,
 * `allowsCustomPickup` — which defaults to FALSE in the product, so a test about
 * custom pickup points has to ask for it), and `$schedule` reaches the schedule.
 */
function readyCommute(string $token, string $vehicleId, array $overrides = [], array $schedule = []): string
{
    $id = createCommute($token, $vehicleId, $overrides)->assertStatus(201)->json('data.id');

    saveRoute($token, $id)->assertOk();
    saveSchedule($token, $id, $schedule)->assertOk();

    return $id;
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

/*
|--------------------------------------------------------------------------
| Seat requests, bookings and groups (Phase 7)
|--------------------------------------------------------------------------
*/

/**
 * A passenger asking a driver for a seat.
 *
 * Defaults to a trial on one named day, because that is the shape most tests want;
 * `commitment` and `requestedDaysMask` in `$overrides` make it a recurring request.
 */
function requestSeat(string $token, string $commuteId, array $overrides = [])
{
    return test()->withToken($token)->postJson("/api/v1/commutes/{$commuteId}/seat-requests", array_merge([
        'commitment' => 'trial',
        'scheduledTripId' => $overrides['scheduledTripId'] ?? null,
        'seats' => 1,
        'meetingPreference' => 'gate',
        'introMessage' => 'أهلاً! بنقل نفس الطريق وحابة أجرب مجموعتك.',
        'paymentType' => 'cash',
        'agreedToRules' => true,
    ], $overrides));
}

/**
 * Approves a request and hands back the one booking it produced.
 *
 * The endpoint answers with an approval, not a booking: a trial produces one and a
 * recurring membership produces one per committed day, and one response shape for
 * both beats two endpoints. Tests about a single trial day want the booking, so it is
 * unwrapped here rather than in every one of them.
 */
function approveSeat(string $driverToken, string $requestId): string
{
    return test()->withToken($driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(201)
        ->json('data.bookings.0.id');
}

/*
|--------------------------------------------------------------------------
| Admin dashboard (Chapter 12)
|--------------------------------------------------------------------------
*/

/**
 * A staff account with a role, its permissions synced, and a REAL base32 TOTP secret
 * so a test can generate a code that actually verifies.
 *
 * Returns the model; the secret is readable from it (`$admin->mfa_secret`) because the
 * cast decrypts on read.
 */
function adminWithRole(AdminRole $role, array $attributes = []): AdminUser
{
    app(SyncAdminRolesAction::class)->execute();

    $admin = AdminUser::factory()->create($attributes);

    $admin->assignRole($role->value);

    return $admin->refresh();
}

/**
 * The code an authenticator app would be showing for this admin right now.
 */
function currentTotpCode(AdminUser $admin): string
{
    return app(Google2FA::class)->getCurrentOtp($admin->mfa_secret);
}

/**
 * Signs an admin in the way the dashboard does — including the session flag
 * `EnsureAdminMfaIsConfirmed` looks for, so tests exercise pages rather than the login
 * form.
 *
 * Deliberately NOT `actingAs()` alone: that would leave the MFA flag unset and every
 * page would bounce to the login screen, which is exactly the protection being relied
 * on elsewhere.
 */
function actingAsAdmin(AdminUser $admin): AdminUser
{
    test()->actingAs($admin, 'admin')
        ->withSession([EnsureAdminMfaIsConfirmed::PASSED => true]);

    return $admin;
}

/**
 * A point almost exactly on the straight line between Rehab and Smart Village, so the
 * straight-line test engine measures a detour of about nothing.
 *
 * In Pest.php rather than one test file because three files now measure against it, and a
 * helper defined in a test file only exists when that file happens to be compiled
 * (standard #43).
 *
 * @return array<string, mixed>
 */
function onTheWay(): array
{
    return ['lat' => 30.0654, 'lng' => 31.2314, 'label' => 'أمام صيدلية العبور'];
}
