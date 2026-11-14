<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);
    AdminUser::factory()->create();
});

it('approves a test driver whose ID and selfie are still waiting for review', function () {
    $token = signIn(phone: '01055501001', devicePublicId: 'karim')['session']['accessToken'];
    completeBasicProfile($token);
    submitGovernmentId($token);
    submitSelfie($token);
    test()->withToken($token)->postJson('/api/v1/driver/application')->assertStatus(201);
    completeDriverApplication($token);
    test()->withToken($token)->postJson('/api/v1/driver/application/submit')->assertOk();

    test()->withToken($token)->postJson('/api/v1/testing/driver/approve')
        ->assertOk()
        ->assertJsonPath('data.driverStatus', 'APPROVED');

    expect(DriverProfile::sole()->status)->toBe(DriverProfileStatus::Approved);
});

it('keeps the driver lists working after a passenger on the commute deletes her account', function () {
    $driverToken = approvedDriver(phone: '01055501001', devicePublicId: 'karim');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();
    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $booked = verifiedPassenger('01112223344');
    approveSeat($driverToken, requestSeat($booked, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));

    $waiting = verifiedPassenger('01112223355', device: 'pax-2');
    requestSeat($waiting, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201);

    test()->withToken($waiting)->deleteJson('/api/v1/account')->assertOk();
    test()->withToken($booked)->deleteJson('/api/v1/testing/account')->assertOk();

    $home = test()->withToken($driverToken)->getJson('/api/v1/driver/home')->assertOk();
    $requests = test()->withToken($driverToken)->getJson('/api/v1/driver/seat-requests')->assertOk();

    expect($requests->json('data'))->toHaveCount(2)
        ->and(collect($requests->json('data'))->pluck('passenger.publicFirstName')->filter()->all())->toBe([])
        ->and($home->json('data.notices'))->toHaveCount(1)
        ->and($home->json('data.notices.0.kind'))->toBe('seat_opened')
        ->and($home->json('data.notices.0.person.publicFirstName'))->toBeNull();
});
