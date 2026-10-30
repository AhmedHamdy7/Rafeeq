<?php

use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Trip\Models\TripLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Asking for a seat on a run that is already moving (Feature 03 v5 §148, §416).
 *
 * > *"Driver may accept new active-ride requests if feasible; those separate incoming active-ride
 * > requests **expire in 3 minutes**."*
 *
 * > *"**No fixed offer expiry.** On active trip, reachability depends on **actual route progress and
 * > predicted ETA**. Trip start alone doesn't invalidate offers."*
 *
 * 🔴 v5 names the reachability model and does not define it, so what it is measured from is the
 * substance of this file: how far along her own route the car has got, and whether the detour still
 * fits before the arrival the people aboard were promised. **Never a clock** — v5 forbids a fixed
 * limit twice, and a time rule would close this on a run crawling five minutes from her gate and
 * leave it open on a run that had already passed her street.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();

    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');

    // The run is out on the road.
    underway($this->driverToken, $this->trip->id);
});

/**
 * Puts the car somewhere along its route, which is what reachability is measured against.
 */
function carAt(string $driverToken, string $tripId, float $lat, float $lng): void
{
    reportPosition($driverToken, $tripId, [[
        'lat' => $lat,
        'lng' => $lng,
        'recordedAt' => now()->toIso8601String(),
        'accuracyMeters' => 10,
    ]])->assertOk();
}

/**
 * The reference route runs Rehab (31.49) → Smart Village (30.97), so a smaller longitude is
 * further along it.
 */
function askDuringRide(string $token, string $commuteId, string $tripId, array $overrides = [])
{
    return requestSeat($token, $commuteId, array_merge([
        'scheduledTripId' => $tripId,
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road, Rehab exit'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
    ], $overrides));
}

it('lets her ask while the car is still behind her stop', function () {
    // Near the start, well before the Ring Road pickup.
    carAt($this->driverToken, $this->trip->id, 30.0594, 31.4800);

    $request = askDuringRide($this->paxToken, $this->commuteId, $this->trip->id)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'PENDING')
        // 🔴 Flagged so the driver's app can put it in the separate sheet v5 describes.
        ->assertJsonPath('data.requestedDuringRide', true)
        ->json('data');

    /*
     * 🔴 Three minutes, and v5 says this is the ONLY countdown left in the product. The question is
     * perishable: she is asking about where the car is now.
     */
    $expires = Carbon::parse($request['expiresAt']);

    expect($expires->diffInMinutes(now(), absolute: true))->toBeLessThanOrEqual(3);
});

/**
 * 🔴 And refused once the car has gone past her — the one reason a clock could never get right.
 */
it('refuses her once the car has passed her stop', function () {
    // Beyond the Ring Road pickup, towards Smart Village.
    carAt($this->driverToken, $this->trip->id, 30.0684, 31.2000);

    askDuringRide($this->paxToken, $this->commuteId, $this->trip->id)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'PICKUP_UNREACHABLE')
        ->assertJsonPath('error.fields.reachability.0', 'already_passed');
});

/**
 * 🔴 The driver's switch, built on 2026-10-24 before anything read it — and a driver who turned it
 * off then is honoured the first time it means something, which was the whole argument for
 * collecting her answer early.
 */
it('refuses her when the driver said not during a ride', function () {
    ScheduledTrip::query()->whereKey($this->trip->id)
        ->update(['receives_requests_during_ride' => false]);

    carAt($this->driverToken, $this->trip->id, 30.0594, 31.4800);

    askDuringRide($this->paxToken, $this->commuteId, $this->trip->id)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.duringRide.0', 'NOT_ACCEPTING');
});

/**
 * ⚠️ No position report means we cannot place the car, and the honest reading of silence is no — a
 * seat offered on that basis is a lift that may never come.
 */
it('refuses her when nothing knows where the car is', function () {
    expect(TripLocation::query()->count())->toBe(0);

    askDuringRide($this->paxToken, $this->commuteId, $this->trip->id)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.reachability.0', 'position_unknown');
});

it('refuses her when the day is full', function () {
    ScheduledTrip::query()->whereKey($this->trip->id)
        ->update(['seats_taken' => ScheduledTrip::query()->whereKey($this->trip->id)->sole()->seats_total]);

    carAt($this->driverToken, $this->trip->id, 30.0594, 31.4800);

    askDuringRide($this->paxToken, $this->commuteId, $this->trip->id)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.seatsAvailable.0', '0');
});

/**
 * 🔴 And the ordinary rules still apply to an ordinary request. A day that has not set off is
 * refused past its deadline exactly as before — this path replaces those checks rather than
 * loosening them, so nothing about tomorrow's booking changed.
 */
it('leaves a request on a run that has not started under the old rules', function () {
    $otherDay = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->whereKeyNot($this->trip->getKey())
        ->orderBy('trip_date')
        ->firstOrFail();

    // Past its deadline, and not under way.
    ScheduledTrip::query()->whereKey($otherDay->getKey())->update([
        'booking_deadline_at' => now()->subHour(),
    ]);

    askDuringRide($this->paxToken, $this->commuteId, $otherDay->getKey())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'BOOKING_DEADLINE_PASSED');
});

/**
 * The driver sees it as a live question with a flag, which is what lets her app separate the sheet
 * from tomorrow's inbox.
 */
it('shows the driver which requests are about this run', function () {
    carAt($this->driverToken, $this->trip->id, 30.0594, 31.4800);

    askDuringRide($this->paxToken, $this->commuteId, $this->trip->id)->assertStatus(201);

    test()->flushHeaders()->withToken($this->driverToken)
        ->getJson('/api/v1/driver/seat-requests')
        ->assertOk()
        ->assertJsonPath('data.0.requestedDuringRide', true);
});

/**
 * And an ordinary request is not flagged, so the two sheets cannot blur.
 */
it('does not flag an ordinary request', function () {
    $otherPax = verifiedPassenger('01222220007', device: 'pax-7');

    $otherDay = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->whereKeyNot($this->trip->getKey())
        ->orderBy('trip_date')
        ->firstOrFail();

    requestSeat($otherPax, $this->commuteId, ['scheduledTripId' => $otherDay->getKey()])
        ->assertStatus(201)
        ->assertJsonPath('data.requestedDuringRide', false);

    expect(SeatRequest::query()->where('requested_during_ride', true)->count())->toBe(0);
});

/**
 * ⚠️ And she can still be approved after the run ends. The flag records the circumstances the
 * request was made in, not its state — so a support conversation months later can still tell that
 * she asked a moving car.
 */
it('keeps the flag after the run is over', function () {
    carAt($this->driverToken, $this->trip->id, 30.0594, 31.4800);

    $requestId = askDuringRide($this->paxToken, $this->commuteId, $this->trip->id)
        ->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->trip->id}/status", ['status' => 'IN_PROGRESS'])->assertOk();

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->trip->id}/complete")->assertOk();

    expect(SeatRequest::query()->whereKey($requestId)->sole()->requested_during_ride)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The three-minute clock
|--------------------------------------------------------------------------
*/

/**
 * 🔴 It cannot be approved once the window has gone, and this is enforced by READING `expires_at`
 * rather than by trusting the status.
 *
 * The sweep that marks requests expired ran with the daily membership roll, which was right while
 * every window was forty-eight hours. Three minutes is not: between the expiry and the sweep there
 * was a long stretch in which a driver could still approve — seating somebody who had been told,
 * correctly, that her question had timed out.
 */
it('cannot be approved once the three minutes are up', function () {
    carAt($this->driverToken, $this->trip->id, 30.0594, 31.4800);

    $requestId = askDuringRide($this->paxToken, $this->commuteId, $this->trip->id)
        ->assertStatus(201)->json('data.id');

    $this->travel(4)->minutes();

    // Still `PENDING` in the column — the sweep has not run — and still refused.
    expect(SeatRequest::query()->whereKey($requestId)->sole()->status->value)->toBe('pending');

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_NOT_PENDING');
});

/**
 * And the sweep catches the status up, so the driver's sheet stops showing a live question that has
 * already gone.
 */
it('is swept to expired within the minute', function () {
    carAt($this->driverToken, $this->trip->id, 30.0594, 31.4800);

    $requestId = askDuringRide($this->paxToken, $this->commuteId, $this->trip->id)
        ->assertStatus(201)->json('data.id');

    $this->travel(4)->minutes();

    test()->artisan('requests:expire')->assertExitCode(0);

    expect(SeatRequest::query()->whereKey($requestId)->sole()->status->value)->toBe('expired');
});

/**
 * ⚠️ And an ordinary request keeps its forty-eight hours — the minute-ly sweep must not shorten
 * anybody else's window.
 */
it('leaves an ordinary request’s window alone', function () {
    $otherPax = verifiedPassenger('01222220008', device: 'pax-8');

    $otherDay = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->whereKeyNot($this->trip->getKey())
        ->orderBy('trip_date')
        ->firstOrFail();

    $requestId = requestSeat($otherPax, $this->commuteId, ['scheduledTripId' => $otherDay->getKey()])
        ->assertStatus(201)->json('data.id');

    $this->travel(4)->minutes();

    test()->artisan('requests:expire')->assertExitCode(0);

    expect(SeatRequest::query()->whereKey($requestId)->sole()->status->value)->toBe('pending');
});
