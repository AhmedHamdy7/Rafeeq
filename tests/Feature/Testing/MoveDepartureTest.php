<?php

use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $this->trips = ScheduledTrip::query()->orderBy('trip_date')->pluck('id')->all();
});

function moveRun(string $token, string $tripId, int $minutes = 30)
{
    return test()->withToken($token)->postJson("/api/v1/testing/trips/{$tripId}/departure", ['minutesFromNow' => $minutes]);
}

function cairoDate(ScheduledTrip $trip): string
{
    return $trip->trip_date->toDateString();
}

it('moves a later run onto a day that already has a scheduled run, swapping the two', function () {
    [$first, $later] = [$this->trips[0], $this->trips[2]];
    $laterDate = cairoDate(ScheduledTrip::query()->findOrFail($later));

    moveRun($this->driverToken, $first)->assertOk();
    $today = cairoDate(ScheduledTrip::query()->findOrFail($first));

    $moved = moveRun($this->driverToken, $later)->assertOk()->json('data');

    expect($moved['tripId'])->toBe($later)
        ->and($moved['swappedTrip']['tripId'])->toBe($first)
        ->and(cairoDate(ScheduledTrip::query()->findOrFail($later)))->toBe($today)
        ->and(cairoDate(ScheduledTrip::query()->findOrFail($first)))->toBe($laterDate)
        ->and(abs(now()->addMinutes(30)->diffInSeconds(ScheduledTrip::query()->findOrFail($later)->departure_at, false)))->toBeLessThan(90);

    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$later}/start")->assertStatus(201);
});

it('moves a finished run on that day back to a free earlier day', function () {
    [$first, $later] = [$this->trips[0], $this->trips[2]];

    moveRun($this->driverToken, $first)->assertOk();
    $today = CarbonImmutable::parse(cairoDate(ScheduledTrip::query()->findOrFail($first)));
    ScheduledTrip::query()->whereKey($first)->update(['status' => ScheduledTripStatus::Completed->value]);

    moveRun($this->driverToken, $later)->assertOk();

    expect(cairoDate(ScheduledTrip::query()->findOrFail($later)))->toBe($today->toDateString())
        ->and(cairoDate(ScheduledTrip::query()->findOrFail($first)))->toBe($today->subDay()->toDateString())
        ->and(ScheduledTrip::query()->findOrFail($first)->status)->toBe(ScheduledTripStatus::Completed);
});

it('refuses with TRIP_DAY_TAKEN when the run on that day is underway', function () {
    [$first, $later] = [$this->trips[0], $this->trips[2]];

    moveRun($this->driverToken, $first, 10)->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$first}/start")->assertStatus(201);

    moveRun($this->driverToken, $later)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_DAY_TAKEN')
        ->assertJsonPath('error.fields.tripId.0', $first)
        ->assertJsonPath('error.fields.status.0', 'PREPARING');
});
