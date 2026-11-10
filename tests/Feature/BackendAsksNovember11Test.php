<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\LiveShare;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('documents');
});

function publishedCommute(): array
{
    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id, ['allowsCustomPickup' => true]);

    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    return [$driverToken, $commuteId, ScheduledTrip::query()->orderBy('trip_date')->first()->id];
}

it('lists commutes in Discover with no points, soonest first', function () {
    [, $commuteId, $tripId] = publishedCommute();
    $paxToken = verifiedPassenger('01112223344');

    $page = test()->withToken($paxToken)->getJson('/api/v1/search/commutes/browse')->assertOk();

    expect($page->json('data'))->toHaveCount(1)
        ->and($page->json('data.0.commuteId'))->toBe($commuteId)
        ->and($page->json('data.0.tripId'))->toBe($tripId)
        ->and($page->json('data.0.meetingPoint'))->toBeNull()
        ->and($page->json('data.0.seatsAvailable'))->toBeGreaterThan(0)
        ->and($page->json('meta.hasMore'))->toBeFalse();
});

it('previews the cost of a drop-off', function () {
    [, $commuteId] = publishedCommute();
    $paxToken = verifiedPassenger('01112223344');

    $preview = test()->withToken($paxToken)
        ->postJson("/api/v1/commutes/{$commuteId}/dropoff-preview", ['lat' => 30.0684, 'lng' => 31.1015])
        ->assertOk()
        ->json('data');

    expect($preview)->toHaveKeys(['addedMinutes', 'runTotalMinutes', 'tier', 'withinLimit', 'estimatedDropoffAt'])
        ->and($preview['withinLimit'])->toBeTrue();

    test()->withToken($paxToken)
        ->postJson("/api/v1/commutes/{$commuteId}/dropoff-preview", ['lat' => 30.60, 'lng' => 31.40])
        ->assertOk()
        ->assertJsonPath('data.withinLimit', false);
});

it('edits the points of a pending seat request', function () {
    [$driverToken, $commuteId, $tripId] = publishedCommute();
    $paxToken = verifiedPassenger('01112223344');

    $requestId = requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id');

    test()->withToken($paxToken)->patchJson("/api/v1/seat-requests/{$requestId}", [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3500, 'label' => 'Rehab gate 2'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
    ])->assertOk();

    expect(round((float) SeatRequest::sole()->requested_pickup_lat, 4))->toBe(30.063);

    test()->withToken($paxToken)->patchJson("/api/v1/seat-requests/{$requestId}", [
        'pickup' => ['lat' => 30.60, 'lng' => 31.40, 'label' => 'Far away'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'PICKUP_DETOUR_TOO_LONG');

    approveSeat($driverToken, $requestId);

    test()->withToken($paxToken)->patchJson("/api/v1/seat-requests/{$requestId}", [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3500, 'label' => 'Rehab gate 2'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
    ])->assertStatus(409)->assertJsonPath('error.code', 'SEAT_REQUEST_NOT_PENDING');
});

it('returns the InstaPay payee on the debt screen', function () {
    $token = verifiedPassenger('01112223344');

    expect(test()->withToken($token)->getJson('/api/v1/account/debt')->assertOk()->json('data.instapay'))->toBeNull();

    config([
        'rafeeq.debt.instapay.link' => 'https://ipn.eg/S/rafeeq/instapay/abc',
        'rafeeq.debt.instapay.handle' => 'rafeeq@instapay',
        'rafeeq.debt.instapay.display_name' => 'Rafeeq',
    ]);

    test()->withToken($token)->getJson('/api/v1/account/debt')
        ->assertOk()
        ->assertJsonPath('data.instapay.handle', 'rafeeq@instapay')
        ->assertJsonPath('data.instapay.displayName', 'Rafeeq');
});

it('filters live shares by run', function () {
    [$driverToken, , $tripId] = publishedCommute();

    underway($driverToken, $tripId);

    test()->withToken($driverToken)->postJson("/api/v1/trips/{$tripId}/live-share")->assertSuccessful();

    $sessionId = TripSession::sole()->id;

    test()->withToken($driverToken)->getJson("/api/v1/safety/live-shares?tripSessionId={$sessionId}")
        ->assertOk()->assertJsonCount(1, 'data');

    test()->withToken($driverToken)->getJson('/api/v1/safety/live-shares?tripSessionId='.Str::ulid())
        ->assertOk()->assertJsonCount(0, 'data');

    expect(LiveShare::query()->count())->toBe(1);
});

it('answers a car edit under review with a car-specific code', function () {
    $token = readyDriverApplicant();
    completeDriverApplication($token);
    test()->withToken($token)->postJson('/api/v1/driver/application/submit')->assertOk();

    test()->withToken($token)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Kia', 'model' => 'Cerato', 'year' => 2020,
        'colour' => 'White', 'plateNumber' => 'XYZ 9876', 'seats' => 5,
    ])->assertStatus(409)->assertJsonPath('error.code', 'VEHICLE_LOCKED');
});

it('keeps the fixed OTP to the test numbers once they are listed', function () {
    config([
        'rafeeq.auth.otp.dev_fixed_code' => '123456',
        'rafeeq.testing.phones' => ['+20101111111?'],
    ]);

    foreach (['01011111111', '01112223344'] as $phone) {
        test()->postJson('/api/v1/auth/otp/request', [
            'phone' => $phone,
            'device' => ['publicId' => 'dev-'.$phone, 'platform' => 'android'],
        ])->assertStatus(202);
    }

    expect(Hash::check('123456', OtpChallenge::query()->where('phone_e164', '+201011111111')->sole()->code_hash))->toBeTrue()
        ->and(Hash::check('123456', OtpChallenge::query()->where('phone_e164', '+201112223344')->sole()->code_hash))->toBeFalse();
});

it('hides the testing endpoints unless they are on and the caller is a test number', function () {
    $token = readyDriverApplicant(phone: '01011111111');

    test()->withToken($token)->postJson('/api/v1/testing/driver/approve')->assertStatus(404);

    config(['rafeeq.testing.api' => true, 'rafeeq.testing.phones' => ['+201099999999']]);

    test()->withToken($token)->postJson('/api/v1/testing/driver/approve')->assertStatus(404);
});

it('approves a test driver in one call, with a Comfort car', function () {
    config(['rafeeq.testing.api' => true, 'rafeeq.testing.phones' => ['+20101111111?']]);
    AdminUser::factory()->create();

    $token = readyDriverApplicant(phone: '01011111111');
    completeDriverApplication($token);
    test()->withToken($token)->postJson('/api/v1/driver/application/submit')->assertOk();

    test()->withToken($token)->postJson('/api/v1/testing/driver/approve')
        ->assertOk()
        ->assertJsonPath('data.driverStatus', 'APPROVED')
        ->assertJsonPath('data.vehicles.0.tier', 'COMFORT');

    expect(DriverProfile::sole()->status)->toBe(DriverProfileStatus::Approved)
        ->and(Vehicle::sole()->tierOrDefault())->toBe(VehicleTier::Comfort)
        ->and(Vehicle::sole()->approved_seats)->toBeGreaterThan(0);
});

it('moves a test trip departure and deletes a test account', function () {
    config(['rafeeq.testing.api' => true, 'rafeeq.testing.phones' => ['+20101234567?']]);

    [$driverToken, , $tripId] = publishedCommute();

    $moved = test()->withToken($driverToken)
        ->postJson("/api/v1/testing/trips/{$tripId}/departure", ['minutesFromNow' => 5])
        ->assertOk()
        ->json('data');

    expect(abs(now()->addMinutes(5)->diffInSeconds(ScheduledTrip::query()->find($tripId)->departure_at, false)))->toBeLessThan(90)
        ->and($moved['tripId'])->toBe($tripId);

    test()->withToken($driverToken)->postJson("/api/v1/trips/{$tripId}/start")->assertStatus(201);

    test()->withToken($driverToken)->deleteJson('/api/v1/testing/account')->assertOk();

    expect(User::query()->where('phone_e164', '+201012345678')->exists())->toBeFalse();
});
