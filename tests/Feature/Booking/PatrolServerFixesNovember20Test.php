<?php

use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['rafeeq.testing.api' => true]);
});

function nov20Commute(string $driverToken, string $phone, array $overrides = [], array $schedule = []): array
{
    $driverId = User::query()->where('phone_e164', '+2'.$phone)->value('id');
    $vehicleId = Vehicle::query()->where('driver_profile_id', $driverId)->where('is_active', true)->sole()->id;

    $commuteId = readyCommute($driverToken, $vehicleId, $overrides, $schedule);
    test()->flushHeaders()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    return [$commuteId, ScheduledTrip::query()->where('commute_offer_id', $commuteId)->orderBy('trip_date')->first()->id];
}

it('refuses a pickup far off the published route when the commute is edited', function () {
    $driver = approvedDriver('01020001009', 'driver');
    [$commuteId, $tripId] = nov20Commute($driver, '01020001009');

    $fields = test()->flushHeaders()->withToken($driver)->postJson("/api/v1/driver/commutes/{$commuteId}/change-proposals", [
        'scheduledTripId' => $tripId,
        'scope' => 'all_future_on_this_weekday',
        'pickupPoints' => [
            ['lat' => 30.0624, 'lng' => 31.3614, 'address' => 'Ring Road'],
            ['lat' => 29.9602, 'lng' => 31.2569, 'address' => 'Maadi'],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_ROUTE_INVALID')
        ->json('error.fields');

    expect($fields)->toBe([
        'pickups.1' => ['That pickup point is more than 3 km from your route. Move it closer to your route.'],
    ]);
});

it('still applies a pickup on the road to a commute nobody booked', function () {
    $driver = approvedDriver('01020001009', 'driver');
    [$commuteId, $tripId] = nov20Commute($driver, '01020001009');

    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/driver/commutes/{$commuteId}/change-proposals", [
        'scheduledTripId' => $tripId,
        'scope' => 'all_future_on_this_weekday',
        'pickupPoints' => [['lat' => 30.0624, 'lng' => 31.3614, 'address' => 'Ring Road']],
    ])->assertStatus(201)->assertJsonPath('data.status', 'APPLIED');
});

it('lets a driver switch to a smaller car when every day offers fewer seats than the default', function () {
    $driver = approvedDriver('01020001009', 'driver');
    $perDay = [];

    foreach (DaysMask::weekdaysSunToThu()->bits() as $bit) {
        $perDay[(string) $bit] = ['seatsTotal' => 2];
    }

    [$commuteId] = nov20Commute($driver, '01020001009', ['seatsTotal' => 3], ['perDay' => $perDay]);

    expect(CommuteWeekdaySchedule::query()->where('commute_offer_id', $commuteId)->pluck('seats_total')->unique()->all())->toBe([2]);

    $smaller = secondApprovedCar($driver, 2);

    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/driver/vehicles/{$smaller->id}/activate")->assertOk();

    expect(Vehicle::query()->whereKey($smaller->id)->value('is_active'))->toBeTruthy();
});

it('still refuses the switch when one day offers the default seats', function () {
    $driver = approvedDriver('01020001009', 'driver');

    [$commuteId] = nov20Commute($driver, '01020001009', ['seatsTotal' => 3], ['perDay' => [
        (string) DaysMask::SUNDAY => ['seatsTotal' => 2],
    ]]);

    $smaller = secondApprovedCar($driver, 2);

    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/driver/vehicles/{$smaller->id}/activate")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_SEATS_EXCEED_APPROVED');
});

function secondApprovedCar(string $token, int $seats): Vehicle
{
    test()->flushHeaders()->withToken($token)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Kia', 'model' => 'Picanto', 'year' => 2021, 'colour' => 'white',
        'plateNumber' => 'ق ن ر 9911', 'seats' => 4,
        'transmission' => 'automatic', 'fuelType' => 'petrol',
    ])->assertStatus(201);

    $vehicle = Vehicle::query()->where('plate_number', 'ق ن ر 9911')->sole();
    $vehicle->forceFill(['verification_status' => 'approved', 'approved_seats' => $seats])->save();

    return $vehicle->refresh();
}

it('names her pending request that a seat offer overlaps', function () {
    $ahmed = approvedDriver('01020001008', 'ahmed', seed: 2);
    [$ahmedCommute, $ahmedTrip] = nov20Commute($ahmed, '01020001008');

    $omar = approvedDriver('01020001009', 'omar', seed: 3);
    [$omarCommute, $omarTrip] = nov20Commute($omar, '01020001009', ['seatsTotal' => 1]);

    $mariam = verifiedPassenger('01020001001', device: 'mariam');

    $pendingId = requestSeat($mariam, $ahmedCommute, ['scheduledTripId' => $ahmedTrip])->assertStatus(201)->json('data.id');

    $waitingId = requestSeat($mariam, $omarCommute, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($omar)->postJson("/api/v1/driver/seat-requests/{$waitingId}/waitlist")->assertOk();
    test()->flushHeaders()->withToken($omar)->postJson("/api/v1/driver/commutes/{$omarCommute}/waitlist/offers", ['all' => true])->assertOk();

    $offers = collect(test()->flushHeaders()->withToken($mariam)->getJson('/api/v1/seat-offers')->assertOk()->json('data'));

    $sameMorning = $offers->firstWhere('tripId', $omarTrip);
    $otherMorning = $offers->first(fn (array $offer): bool => $offer['tripId'] !== $omarTrip);

    expect($sameMorning['conflictingSeatRequestId'])->toBe($pendingId)
        ->and($sameMorning['conflictingBookingId'])->toBeNull()
        ->and($otherMorning['conflictingSeatRequestId'])->toBeNull();

    test()->flushHeaders()->withToken($mariam)->getJson("/api/v1/seat-offers/{$sameMorning['id']}")
        ->assertOk()->assertJsonPath('data.conflictingSeatRequestId', $pendingId);

    test()->flushHeaders()->withToken($mariam)->postJson('/api/v1/seat-offers/'.SeatOffer::query()->whereKey($sameMorning['id'])->value('id').'/accept')->assertOk();

    test()->flushHeaders()->withToken($mariam)->getJson("/api/v1/seat-requests/{$pendingId}")
        ->assertOk()
        ->assertJsonPath('data.status', 'PENDING')
        ->assertJsonPath('data.kind', 'waitlist_application');
});

it('gives the passenger the run session id on Home and finds her links by trip id', function () {
    Storage::fake('documents');

    $driver = approvedDriver('01020001009', 'driver');
    [$commuteId, $tripId] = nov20Commute($driver, '01020001009');
    $mariam = verifiedPassenger('01020001001', device: 'mariam');

    approveSeat($driver, requestSeat($mariam, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));

    underway($driver, $tripId);

    $sessionId = TripSession::query()->where('scheduled_trip_id', $tripId)->value('id');

    test()->flushHeaders()->withToken($mariam)->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.nextJourney.tripSessionId', $sessionId);

    test()->flushHeaders()->withToken($mariam)->postJson("/api/v1/trips/{$tripId}/live-share")->assertStatus(201);

    test()->flushHeaders()->withToken($mariam)->getJson("/api/v1/safety/live-shares?tripId={$tripId}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.tripSessionId', $sessionId);

    test()->flushHeaders()->withToken($mariam)->getJson('/api/v1/safety/live-shares?tripId=01ZZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
