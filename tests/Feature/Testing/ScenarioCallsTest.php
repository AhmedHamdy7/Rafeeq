<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);
    AdminUser::factory()->create();
});

function scenarioDriver(): array
{
    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);

    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    return [$driverToken, $commuteId, ScheduledTrip::query()->orderBy('trip_date')->first()->id];
}

function driverId(): string
{
    return DriverProfile::sole()->user_id;
}

it('answers 404 for every scenario call while testing is off', function () {
    config(['rafeeq.testing.api' => false]);
    $token = verifiedPassenger('01112223344');

    test()->withToken($token)->postJson('/api/v1/testing/attendance', ['kind' => 'no_show', 'count' => 1])->assertNotFound();
    test()->withToken($token)->postJson('/api/v1/testing/jobs/reveal-ratings/run')->assertNotFound();
});

it('confirms and rejects the caller own payment as staff would', function () {
    [$driverToken] = scenarioDriver();
    owe(driverId(), 1_800);

    $pay = fn () => test()->withToken($driverToken)->postJson('/api/v1/account/debt/payments', [
        'side' => 'driver', 'amountPiastres' => 900, 'instapayReference' => 'IP'.random_int(100000, 999999),
    ])->assertStatus(201)->json('data.id');

    $first = $pay();

    test()->withToken($driverToken)->postJson("/api/v1/testing/debt/payments/{$first}/confirm")
        ->assertOk()->assertJsonPath('data.status', 'confirmed');

    test()->withToken($driverToken)->postJson("/api/v1/testing/debt/payments/{$first}/confirm")
        ->assertStatus(409)->assertJsonPath('error.code', 'DEBT_PAYMENT_NOT_PENDING');

    expect(test()->withToken($driverToken)->getJson('/api/v1/account/debt')->json('data.driver.owedPiastres'))->toBe(900);

    $second = $pay();

    test()->withToken($driverToken)->postJson("/api/v1/testing/debt/payments/{$second}/reject", ['note' => 'No transfer found.'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');

    expect(test()->withToken($driverToken)->getJson('/api/v1/account/debt')->json('data.driver.owedPiastres'))->toBe(900);

    $other = verifiedPassenger('01112223344');
    test()->withToken($other)->postJson("/api/v1/testing/debt/payments/{$second}/confirm")->assertNotFound();
});

it('ends a running wait now', function () {
    [$driverToken, $commuteId, $tripId] = scenarioDriver();
    $paxToken = verifiedPassenger('01112223344');
    $bookingId = approveSeat($driverToken, requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));

    underway($driverToken, $tripId);

    test()->withToken($driverToken)->postJson("/api/v1/trips/{$tripId}/wait-timers", ['bookingId' => $bookingId, ...atPickupOf($bookingId)])->assertStatus(201);

    $timer = test()->withToken($driverToken)
        ->postJson("/api/v1/testing/trips/{$tripId}/wait-timers/expire", ['bookingId' => $bookingId])
        ->assertOk()->json('data');

    expect(abs(now()->diffInSeconds($timer['expiresAt'], false)))->toBeLessThan(5);

    test()->withToken($driverToken)->postJson("/api/v1/trips/{$tripId}/no-show", ['bookingId' => $bookingId])->assertOk();
});

it('runs the Friday deadline now: late fee after one, driving suspended after two', function () {
    [$driverToken] = scenarioDriver();
    owe(driverId(), 2_000);

    $first = test()->withToken($driverToken)->postJson('/api/v1/testing/debt/deadline')->assertOk()->json('data');

    expect($first['overduePiastres'])->toBeGreaterThan(0)
        ->and(DebtEntry::query()->where('type', DebtEntryType::LateFee->value)->count())->toBe(1);

    test()->withToken($driverToken)->postJson('/api/v1/testing/debt/deadline')->assertOk();

    test()->withToken($driverToken)->getJson('/api/v1/account/debt')->assertOk()
        ->assertJsonPath('data.driver.suspendedAt', fn ($at) => $at !== null);
});

it('ages a passenger fee until booking is suspended', function () {
    $token = verifiedPassenger('01112223344');
    $userId = User::query()->where('phone_e164', '+201112223344')->sole()->id;
    owe($userId, 3_000, side: DebtSide::Passenger, type: DebtEntryType::Fee);

    test()->withToken($token)->postJson('/api/v1/testing/debt/age', ['days' => 15])->assertOk();

    test()->withToken($token)->getJson('/api/v1/account/debt')->assertOk()
        ->assertJsonPath('data.passenger.suspendedAt', fn ($at) => $at !== null);
});

it('suspends and reinstates the account and the car', function () {
    [$driverToken] = scenarioDriver();

    test()->withToken($driverToken)->postJson('/api/v1/testing/driver/suspend', ['scope' => 'vehicle', 'reason' => 'Plate mismatch reported'])
        ->assertOk()->assertJsonPath('data.vehicle.verificationStatus', 'SUSPENDED');

    test()->withToken($driverToken)->postJson('/api/v1/testing/driver/reinstate', ['scope' => 'vehicle'])
        ->assertOk()->assertJsonPath('data.vehicle.verificationStatus', 'APPROVED');

    test()->withToken($driverToken)->postJson('/api/v1/testing/driver/suspend', ['scope' => 'account', 'reason' => 'Safety review'])
        ->assertOk()->assertJsonPath('data.accountStatus', 'SUSPENDED');

    test()->withToken($driverToken)->postJson('/api/v1/testing/driver/reinstate', ['scope' => 'account'])
        ->assertOk()->assertJsonPath('data.accountStatus', 'ACTIVE');

    expect(User::query()->findOrFail(driverId())->account_status)->toBe(AccountStatus::Active);
});

it('sets a licence and a registration expiry and runs the expiry checks', function () {
    [$driverToken] = scenarioDriver();

    test()->withToken($driverToken)->postJson('/api/v1/testing/driver/documents/licence/expiry', ['expiresAt' => now()->subDay()->toDateString()])
        ->assertOk();

    expect(DriverProfile::sole()->licence_expiry->isPast())->toBeTrue()
        ->and(DriverProfile::sole()->status->value)->toBe('expired_documents');

    test()->withToken($driverToken)->postJson('/api/v1/testing/driver/documents/vehicle_registration/expiry', ['expiresAt' => now()->addDays(10)->toDateString()])
        ->assertOk();

    test()->withToken($driverToken)->postJson('/api/v1/testing/driver/documents/insurance/expiry', ['expiresAt' => now()->toDateString()])
        ->assertNotFound();
});

it('moves a finished booking back so the rating window closes', function () {
    [$driverToken, $commuteId, $tripId] = scenarioDriver();
    $paxToken = verifiedPassenger('01112223344');
    $bookingId = approveSeat($driverToken, requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));

    journeyCompleted($driverToken, $tripId);

    $before = ScheduledTrip::query()->findOrFail($tripId)->departure_at;

    test()->withToken($paxToken)->postJson("/api/v1/testing/bookings/{$bookingId}/age", ['days' => 9])->assertOk();

    expect((int) round($before->diffInDays(ScheduledTrip::query()->findOrFail($tripId)->departure_at, absolute: true)))->toBe(9);

    rateBooking($paxToken, $bookingId)->assertStatus(409)->assertJsonPath('error.code', 'RATING_WINDOW_CLOSED');

    $stranger = verifiedPassenger('01199998888');
    test()->withToken($stranger)->postJson("/api/v1/testing/bookings/{$bookingId}/age", ['days' => 1])->assertNotFound();

    expect(Booking::query()->findOrFail($bookingId))->not->toBeNull();
});

it('runs a nightly job by name and refuses unknown ones', function () {
    $token = verifiedPassenger('01112223344');

    test()->withToken($token)->postJson('/api/v1/testing/jobs/generate-trips/run')
        ->assertOk()->assertJsonPath('data.command', 'commutes:generate-trips')->assertJsonPath('data.exitCode', 0);

    test()->withToken($token)->postJson('/api/v1/testing/jobs/migrate/run')->assertNotFound();
});

it('seeds past offences so the next one lands on the right rung', function () {
    $token = verifiedPassenger('01112223344');
    $userId = User::query()->where('phone_e164', '+201112223344')->sole()->id;

    test()->withToken($token)->postJson('/api/v1/testing/attendance', ['kind' => 'no_show', 'count' => 1])
        ->assertOk()->assertJsonPath('data.standing', 1)->assertJsonPath('data.nextRung', 2);

    test()->withToken($token)->postJson('/api/v1/testing/attendance', ['kind' => 'late_boarding', 'count' => 2])
        ->assertOk()->assertJsonPath('data.nextRung', 3);

    expect(MemberWarning::query()->where('user_id', $userId)->where('kind', WarningKind::NoShow->value)->count())->toBe(1);
});
