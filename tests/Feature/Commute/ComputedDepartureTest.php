<?php

use App\Domains\Commute\Actions\RecomputeDepartureAction;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\ComputedDeparture;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Notification\Models\Notification;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * C-2 (COM-15, decision C9): the driver says when he has to arrive; RAFEEQ says when he leaves,
 * and moves it as riders join, until the 16-hour lock freezes the day.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver();
    $this->vehicleId = Vehicle::sole()->id;
});

/**
 * An arrival-only commute, published. The helper's default schedule sends a departure, so it is
 * removed here: leaving it out is exactly what makes the departure RAFEEQ's.
 */
function arrivalOnlyCommute(string $token, string $vehicleId): string
{
    $id = createCommute($token, $vehicleId)->assertStatus(201)->json('data.id');

    saveRoute($token, $id)->assertOk();

    test()->withToken($token)->putJson("/api/v1/commutes/{$id}/schedule", [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'promisedArrivalTime' => '09:00:00',
        'startDate' => CarbonImmutable::tomorrow()->toDateString(),
        'endDate' => CarbonImmutable::today()->addMonths(2)->toDateString(),
    ])->assertOk();

    test()->withToken($token)->postJson("/api/v1/commutes/{$id}/publish")->assertOk();

    return $id;
}

/** A day far enough ahead that neither the lock nor the booking deadline is near. */
function aDayNextWeek(string $commuteId): ScheduledTrip
{
    return ScheduledTrip::query()
        ->where('commute_offer_id', $commuteId)
        ->where('departure_at', '>', now()->addDays(3))
        ->orderBy('departure_at')
        ->firstOrFail();
}

it('accepts a schedule with an arrival and no departure', function () {
    $id = arrivalOnlyCommute($this->driverToken, $this->vehicleId);

    expect(CommuteWeekdaySchedule::query()->where('commute_offer_id', $id)->pluck('departure_is_computed')->unique()->all())
        ->toBe([true]);
});

it('refuses a schedule with neither a departure nor an arrival', function () {
    $id = createCommute($this->driverToken, $this->vehicleId)->assertStatus(201)->json('data.id');
    saveRoute($this->driverToken, $id)->assertOk();

    saveSchedule($this->driverToken, $id, ['departureTime' => null])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['departureTime']]]);
});

it('works out each day\'s departure from the arrival and the route', function () {
    $id = arrivalOnlyCommute($this->driverToken, $this->vehicleId);
    $trip = aDayNextWeek($id);

    $expected = app(ComputedDeparture::class)->forTrip($trip);

    expect($expected)->not->toBeNull()
        ->and(CarbonImmutable::parse($trip->departure_at)->equalTo($expected))->toBeTrue()
        // Leaves before it arrives, and on a whole minute.
        ->and(CarbonImmutable::parse($trip->departure_at)->lessThan(CarbonImmutable::parse($trip->promised_arrival_at)))->toBeTrue()
        ->and(CarbonImmutable::parse($trip->departure_at)->second)->toBe(0);
});

it('moves the departure earlier when a rider out of the way is confirmed, and tells the driver', function () {
    $id = arrivalOnlyCommute($this->driverToken, $this->vehicleId);
    $trip = aDayNextWeek($id);
    $before = CarbonImmutable::parse($trip->departure_at);

    $paxToken = verifiedPassenger('01112223344');

    // About a kilometre off the line: inside the 1.5 km match radius and the whole-trip cap,
    // but enough to cost the car a few minutes.
    $requestId = requestSeat($paxToken, $id, [
        'scheduledTripId' => $trip->id,
        'pickup' => ['lat' => 30.0720, 'lng' => 31.3614, 'label' => 'A street off the Ring Road'],
    ])->assertStatus(201)->json('data.id');

    approveSeat($this->driverToken, $requestId);

    $after = CarbonImmutable::parse($trip->refresh()->departure_at);

    expect($after->lessThan($before))->toBeTrue()
        ->and(Notification::query()->where('type', 'departure_time_changed')->exists())->toBeTrue();
});

it('never moves a departure the driver fixed himself', function () {
    $id = readyCommute($this->driverToken, $this->vehicleId, [], ['promisedArrivalTime' => '09:00:00']);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$id}/publish")->assertOk();

    $trip = aDayNextWeek($id);
    $before = CarbonImmutable::parse($trip->departure_at);

    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $id, [
        'scheduledTripId' => $trip->id,
        'pickup' => ['lat' => 30.0720, 'lng' => 31.3614, 'label' => 'A street off the Ring Road'],
    ])->assertStatus(201)->json('data.id');

    approveSeat($this->driverToken, $requestId);

    expect(CarbonImmutable::parse($trip->refresh()->departure_at)->equalTo($before))->toBeTrue();
});

it('freezes the departure inside the 16-hour lock', function () {
    $id = arrivalOnlyCommute($this->driverToken, $this->vehicleId);
    $trip = aDayNextWeek($id);
    $original = CarbonImmutable::parse($trip->departure_at);

    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $id, [
        'scheduledTripId' => $trip->id,
        'pickup' => ['lat' => 30.0720, 'lng' => 31.3614, 'label' => 'A street off the Ring Road'],
    ])->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    $moved = CarbonImmutable::parse($trip->refresh()->departure_at);
    expect($moved->lessThan($original))->toBeTrue();

    // Pretend the day still carries the old time, then step inside the lock: nothing moves it.
    $trip->forceFill(['departure_at' => $original])->save();
    test()->travelTo($original->subHours(10));

    $recompute = app(RecomputeDepartureAction::class);

    expect($recompute->execute($trip->id))->toBeFalse()
        ->and(CarbonImmutable::parse($trip->refresh()->departure_at)->equalTo($original))->toBeTrue();
});
