<?php

use App\Domains\Admin\Actions\SyncAdminRolesAction;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Commute\Models\ScheduledTrip;
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
    ->beforeEach(function (): void {
        /*
         * 🔴 Every test starts at the same moment, and this is not tidiness — the suite
         * was failing after 9pm.
         *
         * A commute stops taking bookings at 21:00 the night before
         * (`commute.booking_deadline_hour`), so a test that books a seat on the soonest
         * generated day passed all afternoon and began returning
         * BOOKING_DEADLINE_PASSED the moment the wall clock crossed nine. Roughly a
         * hundred tests book a seat; all of them were quietly time-of-day dependent, and
         * the failures name a deadline rather than a clock, so the evening it first
         * happened would have been spent looking for a bug in the booking rules.
         *
         * A Saturday at 08:00 Cairo, chosen because it is what the suite has been
         * passing under: the reference commute runs Sunday to Thursday, so the soonest
         * generated day is tomorrow with its deadline still ahead. Tests that need a
         * different moment move the clock themselves with `travelTo`, which still works
         * relative to this one.
         */
        test()->travelTo(CarbonImmutable::parse('2026-09-26 08:00:00', 'Africa/Cairo'));
    })
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
 * Brings a generated day forward so it leaves in `$minutesUntilDeparture` minutes —
 * negative for a run that should already have gone.
 *
 * 🔴 The DAY is moved rather than the clock, and this matters. The soonest generated day
 * is up to a week out, and an access token lives fifteen minutes; travelling to next
 * Sunday morning expires it, so every request after the jump came back 401 and each test
 * "failed" for a reason that had nothing to do with the run. Moving the row keeps the
 * real start-window rule under test — the window is measured against `departure_at`,
 * which is exactly the column being set.
 */
function runLeavingIn(string $tripId, int $minutesUntilDeparture, int $dayOffset = 0): ScheduledTrip
{
    $at = now()->addMinutes($minutesUntilDeparture);

    ScheduledTrip::query()->whereKey($tripId)->update([
        'departure_at' => $at,
        'departure_local' => $at,
        /*
         * `$dayOffset` exists for a test that needs TWO runs both leaving around now:
         * `(commute_offer_id, trip_date)` is unique, so they cannot share a date. The
         * on-time rule reads `departure_at` and nothing else, so nudging the date apart
         * changes nothing it measures — it only keeps the index satisfied.
         */
        'trip_date' => $at->copy()->addDays($dayOffset)->toDateString(),
    ]);

    return ScheduledTrip::query()->whereKey($tripId)->sole();
}

/**
 * Gets the run out on the road, which is the only state a check-in means anything in.
 */
function underway(string $driverToken, string $tripId): void
{
    runLeavingIn($tripId, 10);

    test()->withToken($driverToken)->postJson("/api/v1/trips/{$tripId}/start")->assertStatus(201);
    test()->withToken($driverToken)->postJson("/api/v1/trips/{$tripId}/status", ['status' => 'EN_ROUTE'])->assertOk();
}

function checkIn(string $driverToken, string $tripId, array $payload)
{
    return test()->withToken($driverToken)->postJson("/api/v1/trips/{$tripId}/check-in", $payload);
}

/*
 * Ratings (Phase 10). In Pest.php rather than in one test file because two files need them, and a
 * global function declared in a test file only exists when THAT file is collected — running a
 * single file would otherwise fail on a helper it can see in the editor (standard #43).
 */

/**
 * Gets a journey to the only state a rating means anything in: it happened.
 *
 * `IN_PROGRESS` first, because a run may only be completed from there — `en_route` means she is
 * still collecting people, and finishing from it would record a journey nobody was on.
 */
function journeyCompleted(string $driverToken, string $tripId): void
{
    underway($driverToken, $tripId);

    test()->withToken($driverToken)
        ->postJson("/api/v1/trips/{$tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();

    test()->withToken($driverToken)->postJson("/api/v1/trips/{$tripId}/complete")->assertOk();
}

/**
 * One party rating the other on a completed journey. Five stars unless the test says otherwise.
 *
 * NOT `rate()`: a name that generic in a file every test loads is a collision waiting for the next
 * domain that has a rate of anything.
 */
function rateBooking(string $token, string $bookingId, array $payload = [])
{
    return test()->withToken($token)->postJson("/api/v1/bookings/{$bookingId}/rating", array_merge([
        'stars' => 5,
    ], $payload));
}

/**
 * Both sides rate, which reveals everything — the only state a review is readable in.
 */
function bothRated(string $driverToken, string $paxToken, string $tripId, string $bookingId, array $passengerPayload = [], int $driverStars = 4): void
{
    journeyCompleted($driverToken, $tripId);

    rateBooking($paxToken, $bookingId, $passengerPayload)->assertStatus(201);
    rateBooking($driverToken, $bookingId, ['stars' => $driverStars])->assertStatus(201);
}

/**
 * Sets columns on somebody's `user_stats` row, creating it if it is not there.
 *
 * 🔴 Through the QUERY BUILDER, not the model, and that is the whole reason this helper exists.
 * `UserStat` has no `$fillable` on purpose — it is written only by system jobs, never from user
 * input — so `updateOrCreate()` throws "Add [user_id] to fillable property". That has now caught
 * three separate pieces of work, each time looking like a bug in the feature under test rather
 * than in the setup. One helper, written down once.
 *
 * Creating the row matters as much as updating it: a driver who has not completed a trip has no
 * row at all, because `CompleteTripAction` is what writes the first one.
 *
 * @param  array<string, mixed>  $values
 */
function setUserStat(string $userId, array $values): void
{
    DB::table('user_stats')->updateOrInsert(['user_id' => $userId], $values + ['updated_at' => now()]);
}

/**
 * Sets columns on a driver's `driver_balances` row, creating it if it is not there.
 *
 * 🔴 Through the query builder, for the same reason as `setUserStat`: `DriverBalance` has no
 * `$fillable` on purpose — it is a PROJECTION of `driver_fee_ledger`, written only by the
 * settlement and reconciliation jobs, never from user input. So `updateOrCreate()` throws
 * "Add fillable property", and the error reads as a bug in the balance endpoint rather than in the
 * test's setup.
 *
 * @param  array<string, mixed>  $values
 */
function setDriverBalance(string $driverUserId, array $values): void
{
    DB::table('driver_balances')->updateOrInsert(
        ['driver_profile_id' => $driverUserId],
        $values + ['updated_at' => now()],
    );
}

/**
 * Moves a booking's price, keeping the three money columns consistent.
 *
 * 🔴 `bookings` carries a CHECK constraint — `price = platform_fee + driver_amount` — so changing
 * the price alone is rejected by the database. That is the constraint doing its job: a money row
 * that does not add up is worse than one that is wrong, because nothing downstream can tell which
 * of the three to trust. The fee is recomputed at the settled 3%.
 */
function repriceBooking(string $bookingId, int $pricePiastres): void
{
    $fee = (int) round($pricePiastres * 0.03);

    DB::table('bookings')->where('id', $bookingId)->update([
        'price_snapshot_piastres' => $pricePiastres,
        'platform_fee_snapshot_piastres' => $fee,
        'driver_amount_snapshot_piastres' => $pricePiastres - $fee,
    ]);
}

/**
 * Every `/v1/...` path the application actually serves.
 *
 * @return array<int, string>
 */
function registeredV1Paths(): array
{
    $paths = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_starts_with($uri, '/api/v1/')) {
            continue;
        }

        $paths[] = substr($uri, 4);
    }

    return array_values(array_unique($paths));
}

/**
 * One position on the Rehab → Smart Village road, `$agoSeconds` in the past.
 *
 * @return array<string, mixed>
 */
function position(float $lat = 30.0654, float $lng = 31.2314, int $agoSeconds = 5, ?int $accuracy = 12): array
{
    return [
        'lat' => $lat,
        'lng' => $lng,
        'recordedAt' => now()->subSeconds($agoSeconds)->toIso8601String(),
        'accuracyMeters' => $accuracy,
        'speedKmh' => 48,
    ];
}

/**
 * NOT `report()` — Laravel has a global helper of that name, and redeclaring it is a fatal
 * error before a single test runs.
 */
function reportPosition(string $token, string $tripId, array $points)
{
    return test()->withToken($token)->postJson("/api/v1/trips/{$tripId}/location", ['points' => $points]);
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
