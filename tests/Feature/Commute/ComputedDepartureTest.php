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

    saveSchedule($this->driverToken, $id, ['promisedArrivalTime' => null])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_ARRIVAL_REQUIRED');
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

it('re-times the day when a rider is confirmed, and tells the driver', function () {
    $id = arrivalOnlyCommute($this->driverToken, $this->vehicleId);
    $trip = aDayNextWeek($id);

    // A stale time on the day, so any re-timing is visible whatever the rider's points cost.
    $stale = CarbonImmutable::parse($trip->departure_at)->addMinutes(30);
    $trip->forceFill(['departure_at' => $stale])->save();

    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $id, [
        'scheduledTripId' => $trip->id,
        'pickup' => ['lat' => 30.0720, 'lng' => 31.3614, 'label' => 'A street off the Ring Road'],
    ])->assertStatus(201)->json('data.id');

    approveSeat($this->driverToken, $requestId);

    $trip->refresh();

    // Timed through her points, for the promised arrival.
    expect(CarbonImmutable::parse($trip->departure_at)->equalTo(app(ComputedDeparture::class)->forTrip($trip)))->toBeTrue()
        ->and(CarbonImmutable::parse($trip->departure_at)->lessThan($stale))->toBeTrue()
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
    $computed = CarbonImmutable::parse($trip->departure_at);

    $stale = $computed->addMinutes(30);
    $trip->forceFill(['departure_at' => $stale])->save();

    $recompute = app(RecomputeDepartureAction::class);

    // Ten hours before it leaves: locked, so the day keeps the time it has.
    test()->travelTo($stale->subHours(10));

    expect($recompute->execute($trip->id))->toBeFalse()
        ->and(CarbonImmutable::parse($trip->refresh()->departure_at)->equalTo($stale))->toBeTrue();

    // Three days out: free to move.
    test()->travelTo($stale->subDays(3));

    expect($recompute->execute($trip->id))->toBeTrue()
        ->and(CarbonImmutable::parse($trip->refresh()->departure_at)->equalTo($computed))->toBeTrue();
});
