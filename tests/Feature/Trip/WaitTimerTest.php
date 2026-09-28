<?php

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\WaitTimerOutcome;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\TripWaitTimer;
use Illuminate\Support\Facades\Storage;

/**
 * The five minutes a driver waits at a gate (screen 41, Bible §7).
 *
 * 🔴 What the timer actually is: a small clock that turns an argument into a record. A
 * passenger two minutes away is not a no-show, and a driver who waits for everybody is late
 * for four other people. Before the timer, that tension was settled in the moment by
 * whoever felt strongest about it and left nothing behind — the passenger said she waited,
 * the driver said she didn't, and support had two accounts and no facts.
 *
 * So the timer never leaves on its own and never marks anybody absent. It records how long
 * was actually given.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $this->paxToken = verifiedPassenger('01112223344');
    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));
});

function startTimer(string $driverToken, string $tripId, string $bookingId)
{
    return test()->withToken($driverToken)
        ->postJson("/api/v1/trips/{$tripId}/wait-timers", ['bookingId' => $bookingId]);
}

/*
|--------------------------------------------------------------------------
| Starting and extending
|--------------------------------------------------------------------------
*/

it('starts a five-minute wait for one passenger', function () {
    underway($this->driverToken, $this->tripId);

    $timer = startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(201)->json('data');

    expect($timer['graceSeconds'])->toBe(300)
        ->and($timer['extendedSeconds'])->toBe(0)
        ->and($timer['isRunning'])->toBeTrue()
        ->and($timer['hasExpired'])->toBeFalse()
        ->and($timer['outcome'])->toBeNull()
        // The instant to count down to, and the server's own answer at this moment — a
        // phone with a skewed clock would run to the wrong second, and this timer decides
        // whether somebody is recorded absent.
        ->and($timer['remainingSeconds'])->toBeGreaterThan(290)
        ->and($timer['expiresAt'])->not->toBeEmpty();
});

/**
 * 🔴 Copied onto the row, not read back from settings later. The grace is a policy number
 * the dashboard can change, and a dispute about this morning has to be settled against the
 * promise that was in force this morning.
 */
it('freezes the promised grace onto the timer', function () {
    underway($this->driverToken, $this->tripId);

    $timerId = startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(201)->json('data.id');

    PlatformSetting::query()->create([
        'setting_key' => 'trip.wait_grace_seconds',
        'setting_value' => 900,
        'value_type' => 'integer',
        'description' => 'A longer grace from tomorrow',
    ]);

    expect(TripWaitTimer::query()->whereKey($timerId)->sole()->grace_seconds)->toBe(300);
});

it('follows the grace from settings for a new timer', function () {
    PlatformSetting::query()->create([
        'setting_key' => 'trip.wait_grace_seconds',
        'setting_value' => 180,
        'value_type' => 'integer',
        'description' => 'Three minutes',
    ]);

    underway($this->driverToken, $this->tripId);

    expect(startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(201)->json('data.graceSeconds'))->toBe(180);
});

/**
 * Extends rather than restarts, so the record can still say "she waited five minutes and
 * then gave two more" — a restart would erase the generous half of the story.
 */
it('adds time on top without moving when the waiting began', function () {
    underway($this->driverToken, $this->tripId);

    $timer = startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(201)->json('data');

    $extended = test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/wait-timers/{$timer['id']}/extend")
        ->assertOk()->json('data');

    expect($extended['extendedSeconds'])->toBe(120)
        ->and($extended['graceSeconds'])->toBe(300)
        ->and($extended['startedAt'])->toBe($timer['startedAt'])
        ->and($extended['remainingSeconds'])->toBeGreaterThan($timer['remainingSeconds']);
});

it('adds time twice when the driver keeps waiting', function () {
    underway($this->driverToken, $this->tripId);

    $timerId = startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/wait-timers/{$timerId}/extend")->assertOk();

    expect(test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/wait-timers/{$timerId}/extend")
        ->assertOk()->json('data.extendedSeconds'))->toBe(240);
});

/**
 * 🔴 Allowed after the grace has run out. A driver who sees somebody running towards the
 * car at 5:10 should be able to give them another two minutes — refusing would make the
 * generous act the one the app forbids.
 */
it('still lets the driver give more time after the grace has ended', function () {
    underway($this->driverToken, $this->tripId);

    $timerId = startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(201)->json('data.id');

    TripWaitTimer::query()->whereKey($timerId)->update(['started_at' => now()->subMinutes(6)]);

    $extended = test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/wait-timers/{$timerId}/extend")
        ->assertOk()->json('data');

    expect($extended['extendedSeconds'])->toBe(120)
        ->and($extended['hasExpired'])->toBeFalse();
});

it('reports a timer past its grace as expired, with the overrun', function () {
    underway($this->driverToken, $this->tripId);

    $timerId = startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(201)->json('data.id');

    TripWaitTimer::query()->whereKey($timerId)->update(['started_at' => now()->subMinutes(7)]);

    $timers = test()->withToken($this->driverToken)->getJson("/api/v1/trips/{$this->tripId}/wait-timers")
        ->assertOk()->json('data');

    expect($timers[0]['hasExpired'])->toBeTrue()
        // Negative rather than clamped: the screen keeps showing the timer with "grace
        // ended" beside it, and how long ago it ended is what the driver decides on.
        ->and($timers[0]['remainingSeconds'])->toBeLessThan(0);
});

it('refuses a second timer for the same passenger', function () {
    underway($this->driverToken, $this->tripId);

    startTimer($this->driverToken, $this->tripId, $this->bookingId)->assertStatus(201);

    startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'WAIT_TIMER_ALREADY_RUNNING');

    expect(TripWaitTimer::count())->toBe(1);
});

it('refuses to wait on a run that has not left', function () {
    runLeavingIn($this->tripId, 10);

    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/start")->assertStatus(201);

    startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.tripStatus.0', 'PREPARING');
});

it('refuses to wait on a run that never started', function () {
    runLeavingIn($this->tripId, 10);

    startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_NOT_STARTED');
});

/*
|--------------------------------------------------------------------------
| How the wait ends — and there is no endpoint for it
|--------------------------------------------------------------------------
*/

/**
 * 🔴 "She's here — continue" is a CHECK-IN. One call from the driver, both records
 * consistent. A separate "stop the timer" endpoint would let them disagree, and the
 * disagreement always lands the same way: a timer left running on a passenger who was
 * marked present reads, months later, as somebody abandoned at a gate.
 */
it('closes the timer as arrived when the driver checks her in', function () {
    underway($this->driverToken, $this->tripId);
    startTimer($this->driverToken, $this->tripId, $this->bookingId)->assertStatus(201);

    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    $timer = TripWaitTimer::sole();

    expect($timer->outcome)->toBe(WaitTimerOutcome::Arrived)
        ->and($timer->isRunning())->toBeFalse();
});

/**
 * 🔴 And this is where `late` finally comes from. A passenger who made the run wait is
 * `late`, not `present` — and the distinction is earned by the timer, not guessed from the
 * departure time, which would mark the whole car late whenever the DRIVER was.
 */
it('records a passenger who kept the car waiting as late', function () {
    underway($this->driverToken, $this->tripId);
    startTimer($this->driverToken, $this->tripId, $this->bookingId)->assertStatus(201);

    expect(checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])
        ->assertOk()->json('data.status'))->toBe('LATE');

    // Still counts as having travelled, which is what Phase 8 will collect on: she was in
    // the car, and being late is not the same as not coming.
    expect(Attendance::sole()->status->travelled())->toBeTrue();
});

it('records a passenger nobody waited for as simply present', function () {
    underway($this->driverToken, $this->tripId);

    expect(checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])
        ->assertOk()->json('data.status'))->toBe('PRESENT');
});

/**
 * 🔴 The single most careful judgement in this slice. A driver may always depart — she
 * cannot be held hostage by somebody who is not coming — but departing after ninety seconds
 * of a five-minute grace is a different morning from departing after the grace ran out, and
 * the passenger marked absent is entitled to have that difference recorded.
 */
it('calls it a no-show when the driver waited the full grace', function () {
    underway($this->driverToken, $this->tripId);
    $timerId = startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(201)->json('data.id');

    TripWaitTimer::query()->whereKey($timerId)->update(['started_at' => now()->subMinutes(6)]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])
        ->assertOk();

    expect(TripWaitTimer::sole()->outcome)->toBe(WaitTimerOutcome::NoShow)
        ->and(Attendance::sole()->status)->toBe(AttendanceStatus::PassengerNoShow);
});

it('says the driver left early when she departs inside the grace', function () {
    underway($this->driverToken, $this->tripId);
    startTimer($this->driverToken, $this->tripId, $this->bookingId)->assertStatus(201);

    // Ninety seconds into a five-minute promise.
    TripWaitTimer::query()->update(['started_at' => now()->subSeconds(90)]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])
        ->assertOk();

    /*
     * 🔴 The attendance still says no-show, because the driver did leave without her — but
     * the timer says the grace had not run out. Collapsing the two into one value would
     * hide the only fact a dispute turns on.
     */
    expect(TripWaitTimer::sole()->outcome)->toBe(WaitTimerOutcome::DriverLeft)
        ->and(Attendance::sole()->status)->toBe(AttendanceStatus::PassengerNoShow);
});

/**
 * The outcome is decided by the CLOCK, not by what the driver says it is. There is no field
 * on any request that sets it.
 */
it('gives the driver no way to choose the outcome herself', function () {
    underway($this->driverToken, $this->tripId);
    startTimer($this->driverToken, $this->tripId, $this->bookingId)->assertStatus(201);

    TripWaitTimer::query()->update(['started_at' => now()->subSeconds(30)]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/no-show", [
            'bookingId' => $this->bookingId,
            // Ignored: there is no such field, and the clock says otherwise.
            'outcome' => 'no_show',
        ])->assertOk();

    expect(TripWaitTimer::sole()->outcome)->toBe(WaitTimerOutcome::DriverLeft);
});

it('refuses to extend a timer that has already been answered', function () {
    underway($this->driverToken, $this->tripId);
    $timerId = startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(201)->json('data.id');

    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/wait-timers/{$timerId}/extend")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'WAIT_TIMER_NOT_RUNNING')
        ->assertJsonPath('error.fields.outcome.0', 'ARRIVED');
});

/**
 * A run where the driver waited nine minutes for one person and left another after ninety
 * seconds is a run somebody may ask about, and this list is the answer.
 */
it('keeps the finished waits on the list', function () {
    underway($this->driverToken, $this->tripId);
    startTimer($this->driverToken, $this->tripId, $this->bookingId)->assertStatus(201);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    $timers = test()->withToken($this->driverToken)->getJson("/api/v1/trips/{$this->tripId}/wait-timers")
        ->assertOk()->json('data');

    expect($timers)->toHaveCount(1)
        ->and($timers[0]['outcome'])->toBe('arrived')
        ->and($timers[0]['isRunning'])->toBeFalse()
        // The person, with the usual restraint.
        ->and($timers[0]['person'])->toHaveKey('publicFirstName')
        ->and($timers[0]['person'])->not->toHaveKey('fullName');
});

/*
|--------------------------------------------------------------------------
| Who may do what
|--------------------------------------------------------------------------
*/

/**
 * 🔒 A passenger watching a countdown of her own lateness would be told something the
 * product has no reason to tell her, and could not act on it anyway. The one useful thing —
 * that the car is waiting — belongs in a notification (Phase 12).
 */
it('does not let a passenger see or start a wait timer', function () {
    underway($this->driverToken, $this->tripId);

    test()->withToken($this->paxToken)->getJson("/api/v1/trips/{$this->tripId}/wait-timers")
        ->assertStatus(404);

    startTimer($this->paxToken, $this->tripId, $this->bookingId)->assertStatus(404);
});

it('never lets one driver wait on another driver run', function () {
    underway($this->driverToken, $this->tripId);

    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223334455', devicePublicId: 'driver-2', seed: 2);

    startTimer($otherDriver, $this->tripId, $this->bookingId)->assertStatus(404);
});

it('refuses a timer for a booking on another day', function () {
    $second = ScheduledTrip::query()->where('id', '!=', $this->tripId)->orderBy('departure_at')->first();

    fakeOtpSender();
    $otherPax = verifiedPassenger('01223339999', device: 'pax-2');
    $otherBooking = approveSeat($this->driverToken, requestSeat($otherPax, $this->commuteId, [
        'scheduledTripId' => $second->id,
    ])->assertStatus(201)->json('data.id'));

    underway($this->driverToken, $this->tripId);

    startTimer($this->driverToken, $this->tripId, $otherBooking)->assertStatus(404);
});

it('refuses to extend a timer belonging to a different run', function () {
    underway($this->driverToken, $this->tripId);
    $timerId = startTimer($this->driverToken, $this->tripId, $this->bookingId)
        ->assertStatus(201)->json('data.id');

    $second = ScheduledTrip::query()->where('id', '!=', $this->tripId)->orderBy('departure_at')->first();

    runLeavingIn($second->id, 10, dayOffset: 1);
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$second->id}/start")->assertStatus(201);
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$second->id}/status", ['status' => 'EN_ROUTE'])->assertOk();

    // The timer exists and the caller drives both runs — and it still does not belong here.
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$second->id}/wait-timers/{$timerId}/extend")
        ->assertStatus(404);
});

it('requires a token', function () {
    test()->withoutToken()->getJson("/api/v1/trips/{$this->tripId}/wait-timers")->assertStatus(401);
    test()->withoutToken()->postJson("/api/v1/trips/{$this->tripId}/wait-timers")->assertStatus(401);
});
