<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;

beforeEach(function () {
    $this->driver = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commute = readyCommute($this->driver, Vehicle::sole()->id);

    test()->withToken($this->driver)->postJson("/api/v1/commutes/{$this->commute}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $tokenA = verifiedPassenger('01223339999', device: 'pax-2');
    $bookingA = approveSeat($this->driver, requestSeat($tokenA, $this->commute, ['scheduledTripId' => $this->trip])->assertStatus(201)->json('data.id'));

    $tokenB = verifiedPassenger('01112223344', device: 'pax-3');
    $bookingB = approveSeat($this->driver, requestSeat($tokenB, $this->commute, [
        'scheduledTripId' => $this->trip,
        'pickup' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar'],
        'dropoff' => ['lat' => 30.0710, 'lng' => 30.9750, 'label' => 'Gate'],
    ])->assertStatus(201)->json('data.id'));

    runLeavingIn($this->trip, -240);

    test()->flushHeaders()->withToken($this->driver)->postJson("/api/v1/trips/{$this->trip}/start")->assertStatus(201);
    test()->flushHeaders()->withToken($this->driver)->postJson("/api/v1/trips/{$this->trip}/status", ['status' => 'EN_ROUTE'])->assertOk();
    dtRoute($this->driver, $this->trip);

    $this->first = NavigationStop::query()
        ->where('scheduled_trip_id', $this->trip)
        ->where('kind', NavigationStopKind::PassengerPickup->value)
        ->orderBy('sequence')
        ->value('booking_id');

    [$this->firstToken, $this->second] = $this->first === $bookingA ? [$tokenA, $bookingB] : [$tokenB, $bookingA];
});

function dtRoute(string $token, string $trip)
{
    return test()->flushHeaders()->withToken($token)->getJson("/api/v1/driver/commute-occurrences/{$trip}/navigation-route")->assertOk();
}

function dtCall(string $token, string $booking)
{
    return test()->flushHeaders()->withToken($token)->getJson("/api/v1/bookings/{$booking}/call");
}

function dtExit(string $token, string $booking)
{
    return test()->flushHeaders()->withToken($token)->postJson("/api/v1/bookings/{$booking}/exit-without-fee");
}

it('moves Call to the next passenger after a check-in', function () {
    dtCall($this->driver, $this->second)->assertStatus(409)->assertJsonPath('error.fields.reason.0', 'not_next_pickup');

    checkIn($this->driver, $this->trip, ['bookingId' => $this->first, ...position()])->assertOk();

    $stop = collect(dtRoute($this->driver, $this->trip)->json('data.stops'))
        ->first(fn (array $s): bool => $s['bookingId'] === $this->first && $s['kind'] === 'PASSENGER_PICKUP');

    expect($stop['reachedAt'])->not->toBeNull();

    dtCall($this->driver, $this->second)->assertOk();
    dtCall($this->driver, $this->first)->assertStatus(409);
});

it('moves Call to the next passenger after a no-show', function () {
    test()->flushHeaders()->withToken($this->driver)
        ->postJson("/api/v1/trips/{$this->trip}/no-show", ['bookingId' => $this->first, ...position()])
        ->assertOk();

    dtCall($this->driver, $this->second)->assertOk();
});

it('keeps a decided pickup when the route is rebuilt, or built for the first time', function () {
    checkIn($this->driver, $this->trip, ['bookingId' => $this->first, ...position()])->assertOk();

    NavigationStop::query()->where('scheduled_trip_id', $this->trip)->delete();
    ScheduledTrip::query()->whereKey($this->trip)->update(['itinerary_built_version' => 0, 'itinerary_version' => 5]);

    $stop = collect(dtRoute($this->driver, $this->trip)->json('data.stops'))
        ->first(fn (array $s): bool => $s['bookingId'] === $this->first && $s['kind'] === 'PASSENGER_PICKUP');

    expect($stop['reachedAt'])->not->toBeNull();
});

it('shows a late exit to the driver and takes the passenger off the route', function () {
    dtExit($this->firstToken, $this->first)->assertOk()->assertJsonPath('data.cancelledReason', 'late_for_pickup');

    $rows = collect(test()->flushHeaders()->withToken($this->driver)->getJson("/api/v1/trips/{$this->trip}/attendance")->assertOk()->json('data'))->keyBy('bookingId');

    expect($rows[$this->first]['status'])->toBe('CANCELLED')
        ->and($rows[$this->first]['bookingStatus'])->toBe('EXITED_DUE_TO_DRIVER_DELAY')
        ->and($rows[$this->second]['status'])->toBe('PENDING')
        ->and($rows[$this->second]['bookingStatus'])->toBe('CONFIRMED');

    dtCall($this->driver, $this->second)->assertOk();

    $stops = collect(dtRoute($this->driver, $this->trip)->json('data.stops'));

    expect($stops->pluck('bookingId')->filter()->unique()->values()->all())->toBe([$this->second])
        ->and($stops->pluck('bookingId')->all())->not->toContain($this->first);

    checkIn($this->driver, $this->trip, ['bookingId' => $this->first, ...position()])
        ->assertStatus(409)->assertJsonPath('error.code', 'ATTENDANCE_NOT_CONFIRMABLE');
});

it('doesn’t mark other passengers as passed when someone exits', function () {
    dtExit($this->firstToken, $this->first)->assertOk();

    expect(NavigationStop::query()->where('scheduled_trip_id', $this->trip)->whereNotNull('reached_at')->exists())->toBeFalse();
});

it('refuses a late exit once the passenger is aboard, and charges the driver nothing', function () {
    checkIn($this->driver, $this->trip, ['bookingId' => $this->first, ...position()])->assertOk();

    dtExit($this->firstToken, $this->first)->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_NOT_CANCELLABLE')
        ->assertJsonPath('error.fields.driverDelay.0', 'NOT_DELAYED');

    expect(Booking::findOrFail($this->first)->status->value)->toBe('confirmed')
        ->and(DebtEntry::query()->where('booking_id', $this->first)->exists())->toBeFalse();

    test()->flushHeaders()->withToken($this->firstToken)->getJson("/api/v1/bookings/{$this->first}")
        ->assertOk()->assertJsonPath('data.driverDelay', null);
});

it('lists an ended-early ride for the passenger now, and for the driver after the run', function () {
    checkIn($this->driver, $this->trip, ['bookingId' => $this->first, ...position()])->assertOk();

    test()->flushHeaders()->withToken($this->firstToken)->postJson("/api/v1/bookings/{$this->first}/end-early")->assertOk();

    $mine = test()->flushHeaders()->withToken($this->firstToken)->getJson('/api/v1/ratings/pending')->assertOk()->json('data');

    expect($mine)->toHaveCount(1)
        ->and($mine[0]['bookingId'])->toBe($this->first)
        ->and($mine[0]['direction'])->toBe('passenger_to_driver');

    expect(test()->flushHeaders()->withToken($this->driver)->getJson('/api/v1/ratings/pending')->json('data'))->toBe([]);

    ScheduledTrip::query()->whereKey($this->trip)->update(['status' => 'completed']);

    $drivers = test()->flushHeaders()->withToken($this->driver)->getJson('/api/v1/ratings/pending')->json('data');

    expect(collect($drivers)->pluck('bookingId')->all())->toContain($this->first);

    test()->flushHeaders()->withToken($this->firstToken)->postJson("/api/v1/bookings/{$this->first}/rating", ['stars' => 5])->assertStatus(201);

    expect(test()->flushHeaders()->withToken($this->firstToken)->getJson('/api/v1/ratings/pending')->json('data'))->toBe([]);
});
