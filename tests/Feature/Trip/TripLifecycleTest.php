<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Identity\Models\UserStat;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Storage;

/**
 * Chapter 8 — one day of a commute, from "Start Today's Commute" to "Complete Trip".
 *
 * 🔴 What these really guard is that the sequence cannot be skipped. A run that could go
 * straight from "preparing" to "completed" is a run where nobody was collected and
 * everybody was charged — and the support ticket that follows has no record of which
 * step was missed.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->first();
    $this->tripId = $this->trip->id;
});

function startRun(string $token, string $tripId)
{
    return test()->withToken($token)->postJson("/api/v1/trips/{$tripId}/start");
}

function advanceRun(string $token, string $tripId, string $status)
{
    return test()->withToken($token)->postJson("/api/v1/trips/{$tripId}/status", ['status' => $status]);
}

/*
|--------------------------------------------------------------------------
| Starting
|--------------------------------------------------------------------------
*/

it('starts the run and opens a session in preparing', function () {
    runLeavingIn($this->tripId, 10);

    $session = startRun($this->driverToken, $this->tripId)->assertStatus(201)->json('data');

    expect($session['status'])->toBe('PREPARING')
        ->and($session['startedAt'])->not->toBeNull()
        // The car has not moved yet, so there is no departure and no live map.
        ->and($session['departedAt'])->toBeNull()
        ->and($session['isUnderway'])->toBeFalse();

    // And the trip itself says so, because that is what the rest of the product reads.
    expect(ScheduledTrip::query()->whereKey($this->tripId)->sole()->status->value)->toBe('preparing');
});

/**
 * 🔴 Chapter 8 lists these checks in order, and every one of them is something that was
 * true when the commute was published and may have stopped being true since. A licence
 * expires on a date nobody looks at.
 */
it('refuses to start a run on an expired licence', function () {
    runLeavingIn($this->tripId, 10);

    DriverProfile::sole()->forceFill(['licence_expiry' => now()->subDay()])->save();

    startRun($this->driverToken, $this->tripId)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'DRIVER_LICENCE_EXPIRED');

    expect(TripSession::count())->toBe(0);
});

it('refuses to start a run in a vehicle that has been suspended', function () {
    runLeavingIn($this->tripId, 10);

    Vehicle::sole()->forceFill(['is_active' => false])->save();

    startRun($this->driverToken, $this->tripId)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_VEHICLE_UNAVAILABLE');
});

it('refuses to start a run whose driver is no longer approved', function () {
    runLeavingIn($this->tripId, 10);

    DriverProfile::sole()->forceFill(['status' => 'suspended'])->save();

    startRun($this->driverToken, $this->tripId)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'DRIVER_NOT_ELIGIBLE');
});

/**
 * 🔴 Without a window a driver could put a run "underway" the night before — and two
 * things are read off that state: the live map a passenger watches for a car that is not
 * coming yet, and the GPS trail a dispute is settled from. A trail that starts at
 * midnight proves nothing about a seven o'clock pickup.
 */
it('refuses to start a run the night before', function () {
    startRun($this->driverToken, $this->tripId)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'TRIP_TOO_EARLY_TO_START')
        // The refusal says when the run leaves, so the app can say how long to wait.
        ->assertJsonPath('error.fields.departureAt.0', $this->trip->departure_at->toIso8601String());
});

/**
 * No lower bound, deliberately: a run whose departure has passed is exactly the run a
 * late driver needs to be able to start.
 */
it('lets a late driver start a run that should already have left', function () {
    runLeavingIn($this->tripId, -25);

    startRun($this->driverToken, $this->tripId)->assertStatus(201);
});

it('refuses to start the same run twice', function () {
    runLeavingIn($this->tripId, 10);

    startRun($this->driverToken, $this->tripId)->assertStatus(201);

    startRun($this->driverToken, $this->tripId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_ALREADY_STARTED');

    expect(TripSession::count())->toBe(1);
});

it('refuses to start a cancelled day', function () {
    runLeavingIn($this->tripId, 10);

    ScheduledTrip::query()->whereKey($this->tripId)->update(['status' => 'cancelled']);

    startRun($this->driverToken, $this->tripId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_NOT_CANCELLABLE');
});

/**
 * 🔴 A row that exists and says `pending` records that somebody was EXPECTED and has not
 * been marked yet. With rows created only on check-in, a passenger nobody confirmed is
 * indistinguishable from a passenger who was never on the run — and telling those two
 * apart is the whole of a no-show dispute.
 */
it('opens a pending attendance row for every confirmed seat', function () {
    $paxToken = verifiedPassenger('01112223344');
    approveSeat($this->driverToken, requestSeat($paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));

    runLeavingIn($this->tripId, 10);

    startRun($this->driverToken, $this->tripId)->assertStatus(201);

    $attendance = Attendance::sole();

    expect($attendance->booking_id)->toBe(Booking::sole()->id)
        ->and($attendance->status)->toBe(AttendanceStatus::Pending)
        ->and($attendance->confirmed_by)->toBeNull();
});

/**
 * 🔒 A run is the driver's to start. 404 rather than 403, because a 403 would confirm
 * somebody else's trip id exists.
 */
it('never lets one driver start another driver run', function () {
    runLeavingIn($this->tripId, 10);

    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223334455', devicePublicId: 'driver-2', seed: 2);

    startRun($otherDriver, $this->tripId)->assertStatus(404);

    expect(TripSession::count())->toBe(0);
});

it('never lets a passenger start the run they are riding on', function () {
    $paxToken = verifiedPassenger('01112223344');
    approveSeat($this->driverToken, requestSeat($paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));

    runLeavingIn($this->tripId, 10);

    startRun($paxToken, $this->tripId)->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| The sequence
|--------------------------------------------------------------------------
*/

it('walks the run through the states the chapter sets out', function () {
    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);

    expect(advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')->assertOk()->json('data.status'))
        ->toBe('EN_ROUTE');

    expect(advanceRun($this->driverToken, $this->tripId, 'AT_PICKUP')->assertOk()->json('data.status'))
        ->toBe('AT_PICKUP');

    $moving = advanceRun($this->driverToken, $this->tripId, 'IN_PROGRESS')->assertOk()->json('data');

    expect($moving['status'])->toBe('IN_PROGRESS')
        // The car has moved, so now there is a departure to measure lateness from.
        ->and($moving['departedAt'])->not->toBeNull()
        ->and($moving['isUnderway'])->toBeTrue();
});

/**
 * 🔴 A run with several pickups cycles en route ↔ at pickup, and becomes "in progress"
 * only once everybody is aboard and it is heading for the destination. So the departure
 * is recorded at the LAST pickup, not the first — and the on-time rate would be a
 * different number if it were the other way round.
 *
 * `in_progress → at_pickup` is deliberately not a transition. The cycle is what serves a
 * multi-pickup morning, and allowing the way back would make "in progress" mean nothing.
 */
it('records the departure when the run finally sets off, not at the first pickup', function () {
    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);

    advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')->assertOk();
    advanceRun($this->driverToken, $this->tripId, 'AT_PICKUP')->assertOk();

    // Standing at the first gate is not a departure.
    expect(TripSession::sole()->departed_at)->toBeNull();

    advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')->assertOk();
    advanceRun($this->driverToken, $this->tripId, 'AT_PICKUP')->assertOk();

    expect(TripSession::sole()->departed_at)->toBeNull();

    test()->travelTo(now()->addMinutes(4));
    advanceRun($this->driverToken, $this->tripId, 'IN_PROGRESS')->assertOk();

    expect(TripSession::sole()->departed_at)->not->toBeNull()
        ->and(TripSession::sole()->departed_at->diffInSeconds(now(), absolute: true))
        ->toBeLessThan(5);

    // And once it has set off, it cannot go back to a pickup.
    advanceRun($this->driverToken, $this->tripId, 'AT_PICKUP')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_INVALID_TRANSITION');
});

/**
 * 🔴 The refusal names where the run IS and what it could do instead. A driver who taps
 * "arrived" on a run she has not set off on needs to be told what to do — "invalid
 * transition" tells her the app is broken.
 */
it('refuses a step that is not possible, and says what is', function () {
    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);

    $error = advanceRun($this->driverToken, $this->tripId, 'AT_PICKUP')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_INVALID_TRANSITION')
        ->json('error.fields');

    expect($error['currentStatus'])->toBe(['PREPARING'])
        ->and($error['allowed'])->toContain('EN_ROUTE');
});

it('refuses to advance a run that was never started', function () {
    runLeavingIn($this->tripId, 10);

    advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_NOT_STARTED');
});

/**
 * Once people are in the car the run either finishes or becomes an emergency.
 * "Cancelled" would leave passengers mid-journey with no record of having travelled.
 */
it('will not cancel a run that people are already riding on', function () {
    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);
    advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')->assertOk();
    advanceRun($this->driverToken, $this->tripId, 'IN_PROGRESS')->assertOk();

    advanceRun($this->driverToken, $this->tripId, 'CANCELLED')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_INVALID_TRANSITION');
});

it('rejects a status that is not a state at all', function () {
    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);

    advanceRun($this->driverToken, $this->tripId, 'ARRIVED_SOMEWHERE')->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Completing
|--------------------------------------------------------------------------
*/

it('refuses to complete a run that has not set off', function () {
    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);

    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_INVALID_TRANSITION');
});

it('closes the run and the bookings on it', function () {
    $paxToken = verifiedPassenger('01112223344');
    approveSeat($this->driverToken, requestSeat($paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));

    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);

    // Five minutes sitting in the car before setting off, then eight on the road. The two
    // are deliberately different so the duration can only match one of them.
    test()->travelTo(now()->addMinutes(5));
    advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')->assertOk();
    advanceRun($this->driverToken, $this->tripId, 'IN_PROGRESS')->assertOk();

    test()->travelTo(now()->addMinutes(8));

    $done = test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")
        ->assertOk()->json('data');

    expect($done['status'])->toBe('COMPLETED')
        ->and($done['completedAt'])->not->toBeNull()
        // Measured from when the car moved, not from when the app was opened.
        // ~480s from the departure, not ~780s from the start.
        ->and($done['durationSeconds'])->toBeGreaterThan(420)
        ->and($done['durationSeconds'])->toBeLessThan(540)
        /*
         * Null, because there are no GPS points yet to measure it from. The route's
         * planned distance would be a figure we did not observe presented as one we did.
         */
        ->and($done['distanceTravelledMeters'])->toBeNull();

    expect(Booking::sole()->status->value)->toBe('completed')
        ->and(ScheduledTrip::query()->whereKey($this->tripId)->sole()->status->value)->toBe('completed');
});

/**
 * 🔴 The single most important judgement in this file. Decision D18 lets the DRIVER decide
 * whether a passenger travelled, which the Master Plan itself flags as one party deciding
 * the other's bill. Turning the driver's SILENCE into a no-show would extend that power to
 * things she did not even do: a driver who forgets to tap four names would mark four
 * people absent, and each would carry it on a record strangers read.
 */
it('does not turn an unconfirmed passenger into a no-show', function () {
    $paxToken = verifiedPassenger('01112223344');
    approveSeat($this->driverToken, requestSeat($paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));

    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);
    advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')->assertOk();
    advanceRun($this->driverToken, $this->tripId, 'IN_PROGRESS')->assertOk();

    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    // Still pending: nobody recorded whether this person travelled, and that is not the
    // same as recording that they did not.
    expect(Attendance::sole()->status)->toBe(AttendanceStatus::Pending)
        ->and(Attendance::sole()->checked_out_at)->toBeNull();
});

it('counts the completed run for the driver and the passenger', function () {
    $paxToken = verifiedPassenger('01112223344');
    approveSeat($this->driverToken, requestSeat($paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));

    expect(DriverProfile::sole()->completed_trips_count)->toBe(0);

    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);
    advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')->assertOk();
    advanceRun($this->driverToken, $this->tripId, 'IN_PROGRESS')->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    $passengerId = Booking::sole()->passenger_user_id;

    expect(DriverProfile::sole()->completed_trips_count)->toBe(1)
        ->and(UserStat::query()->whereKey($passengerId)->sole()->completed_trips_as_passenger)->toBe(1)
        // And how many mornings this group has actually shared, which is the number the
        // group screen shows instead of "founded 3 weeks ago".
        ->and(CommuteGroup::sole()->rides_together_count)->toBe(1);
});

/**
 * The number strangers read before getting into somebody's car. Ten minutes of grace
 * because Cairo traffic is not a character flaw.
 */
it('scores a run that left on time at a hundred per cent', function () {
    // Leaves in two minutes and sets off right now, so three minutes inside the grace.
    runLeavingIn($this->tripId, 2);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);
    advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')->assertOk();
    advanceRun($this->driverToken, $this->tripId, 'IN_PROGRESS')->assertOk();

    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    expect((float) DriverProfile::sole()->on_time_rate)->toBe(100.0);
});

it('scores a run that left far too late at zero', function () {
    // The departure was forty minutes ago and the car only moves now.
    runLeavingIn($this->tripId, -40);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);
    advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')->assertOk();
    advanceRun($this->driverToken, $this->tripId, 'IN_PROGRESS')->assertOk();

    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    expect((float) DriverProfile::sole()->on_time_rate)->toBe(0.0);
});

/**
 * 🔴 Recomputed from the rows rather than incremented: a percentage kept by increment
 * drifts the first time a job runs twice, and the wrong value sticks forever with nothing
 * to compare it against.
 */
it('recomputes the rate over every run rather than nudging it', function () {
    // One on time.
    runLeavingIn($this->tripId, 2);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);
    advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')->assertOk();
    advanceRun($this->driverToken, $this->tripId, 'IN_PROGRESS')->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    expect((float) DriverProfile::sole()->on_time_rate)->toBe(100.0);

    // And the next day, thirty minutes late.
    $second = ScheduledTrip::query()->where('id', '!=', $this->tripId)->orderBy('departure_at')->first();

    runLeavingIn($second->id, -30, dayOffset: 1);
    startRun($this->driverToken, $second->id)->assertStatus(201);
    advanceRun($this->driverToken, $second->id, 'EN_ROUTE')->assertOk();
    advanceRun($this->driverToken, $second->id, 'IN_PROGRESS')->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$second->id}/complete")->assertOk();

    // One of two, not "100 minus a bit".
    expect((float) DriverProfile::sole()->on_time_rate)->toBe(50.0);
});

/*
|--------------------------------------------------------------------------
| Reading the run
|--------------------------------------------------------------------------
*/

it('lets a passenger on the run watch it', function () {
    $paxToken = verifiedPassenger('01112223344');
    approveSeat($this->driverToken, requestSeat($paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));

    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);

    $seen = test()->withToken($paxToken)->getJson("/api/v1/trips/{$this->tripId}")
        ->assertOk()->json('data');

    expect($seen['status'])->toBe('PREPARING')
        // 🔒 And not the GPS trail, which is a minute-by-minute record of where somebody
        // was. A passenger needs the car's position now, not the history of it.
        ->and($seen)->not->toHaveKey('locations');
});

/**
 * 🔒 Somebody who cancelled last night has no reason to watch a car drive around this
 * morning. "I used to have a seat" is not a reason to be handed somebody's live position.
 */
it('stops showing the run to a passenger who cancelled', function () {
    $paxToken = verifiedPassenger('01112223344');
    $bookingId = approveSeat($this->driverToken, requestSeat($paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));

    test()->withToken($paxToken)->patchJson("/api/v1/bookings/{$bookingId}/cancel")->assertOk();

    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);

    test()->withToken($paxToken)->getJson("/api/v1/trips/{$this->tripId}")->assertStatus(404);
});

it('hides the run from somebody with no seat on it', function () {
    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);

    fakeOtpSender();
    $stranger = verifiedPassenger('01223339999', device: 'pax-2');

    test()->withToken($stranger)->getJson("/api/v1/trips/{$this->tripId}")->assertStatus(404);
});

it('says the run has not started rather than inventing an empty one', function () {
    test()->withToken($this->driverToken)->getJson("/api/v1/trips/{$this->tripId}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_NOT_STARTED');
});

it('requires a token', function () {
    test()->withoutToken()->getJson("/api/v1/trips/{$this->tripId}")->assertStatus(401);
    test()->withoutToken()->postJson("/api/v1/trips/{$this->tripId}/start")->assertStatus(401);
});

/**
 * The driver home leads with this run, so the two have to agree about it.
 */
it('shows the started run on the driver home as underway', function () {
    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);
    advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')->assertOk();
    advanceRun($this->driverToken, $this->tripId, 'IN_PROGRESS')->assertOk();

    $run = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun');

    expect($run['tripId'])->toBe($this->tripId)
        ->and($run['status'])->toBe('IN_PROGRESS');
});

it('stops leading the driver home with a run that has finished', function () {
    runLeavingIn($this->tripId, 10);
    startRun($this->driverToken, $this->tripId)->assertStatus(201);
    advanceRun($this->driverToken, $this->tripId, 'EN_ROUTE')->assertOk();
    advanceRun($this->driverToken, $this->tripId, 'IN_PROGRESS')->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    $run = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun');

    // The next day's run, or nothing — never the one that just ended.
    expect($run['tripId'] ?? null)->not->toBe($this->tripId);
});

it('keeps the state machine honest about its own transitions', function () {
    // The enum is the single source for what may follow what, and every endpoint reads
    // it — so its shape is worth pinning directly.
    expect(TripSessionStatus::Preparing->canTransitionTo(TripSessionStatus::InProgress))->toBeFalse()
        ->and(TripSessionStatus::Preparing->canTransitionTo(TripSessionStatus::EnRoute))->toBeTrue()
        ->and(TripSessionStatus::InProgress->canTransitionTo(TripSessionStatus::Cancelled))->toBeFalse()
        ->and(TripSessionStatus::InProgress->canTransitionTo(TripSessionStatus::Completed))->toBeTrue()
        ->and(TripSessionStatus::Completed->isTerminal())->toBeTrue()
        // Reachable from every live state, and from nowhere else.
        ->and(TripSessionStatus::Preparing->canTransitionTo(TripSessionStatus::Emergency))->toBeTrue()
        ->and(TripSessionStatus::Completed->canTransitionTo(TripSessionStatus::Emergency))->toBeFalse();
});
