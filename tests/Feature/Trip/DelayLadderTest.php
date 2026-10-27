<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Trip\Actions\SweepDelayedTripsAction;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Models\Attendance;
use Illuminate\Support\Facades\Storage;

/**
 * The driver-not-started ladder (Feature 03 v5 §240).
 *
 * > *"Driver not started: +1m reminder; +5m passenger warning; +10m violation/escalation; +20m
 * > auto-EXPIRED only if never started. If started at +12m, remains IN_PROGRESS; existing violation
 * > remains."*
 *
 * 🔴 The sweep runs every minute, so the thing most of these tests check is that each rung fires
 * ONCE. The failure mode if it does not is not subtle: a woman standing on a kerb gets the same
 * push fifteen times while her driver is still not coming.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');

    $this->bookingId = approveSeat(
        $this->driverToken,
        requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
            ->assertStatus(201)->json('data.id'),
    );

    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $this->pax = User::query()->where('phone_e164', '+201223339999')->sole();
});

/**
 * Puts the day this many minutes past its departure and runs the sweep.
 *
 * @return array{reminded: int, warned: int, violations: int, expired: int}
 */
function sweepAt(string $tripId, int $minutesLate): array
{
    runLeavingIn($tripId, -$minutesLate);

    return app(SweepDelayedTripsAction::class)->sweep();
}

function countNotifications(string $userId, string $type): int
{
    return Notification::query()->where('user_id', $userId)->where('type', $type)->count();
}

/*
|--------------------------------------------------------------------------
| The rungs
|--------------------------------------------------------------------------
*/

it('says nothing at all before departure', function () {
    runLeavingIn($this->tripId, 5);

    expect(app(SweepDelayedTripsAction::class)->sweep())
        ->toBe(['reminded' => 0, 'warned' => 0, 'violations' => 0, 'expired' => 0]);

    expect(Notification::query()->where('type', 'commute_not_started_reminder')->count())->toBe(0);
});

/**
 * +1: the driver first. A minute is not an accusation — the commonest reason a run has not started
 * is that she is already driving and has not tapped anything.
 */
it('reminds the driver a minute after departure, and only her', function () {
    expect(sweepAt($this->tripId, 1)['reminded'])->toBe(1);

    expect(countNotifications($this->driver->id, 'commute_not_started_reminder'))->toBe(1)
        // Nothing to the passenger yet: four more minutes of a driver who may be two streets away.
        ->and(countNotifications($this->pax->id, 'commute_not_started_warning'))->toBe(0);
});

/**
 * 🔴 The sweep runs every minute. Fifteen identical pushes about one late morning is how
 * notifications stop being read.
 */
it('reminds her once, however many times the sweep runs', function () {
    foreach ([1, 2, 3, 4] as $minutesLate) {
        sweepAt($this->tripId, $minutesLate);
    }

    expect(countNotifications($this->driver->id, 'commute_not_started_reminder'))->toBe(1);
});

/**
 * +5: the people waiting. Five minutes of standing on a kerb wondering is the thing this ends.
 */
it('warns the passenger at five minutes, once', function () {
    $counts = sweepAt($this->tripId, 5);

    expect($counts['warned'])->toBe(1)
        // The driver's reminder goes out in the same pass if it had not already.
        ->and($counts['reminded'])->toBe(1);

    sweepAt($this->tripId, 6);
    sweepAt($this->tripId, 7);

    expect(countNotifications($this->pax->id, 'commute_not_started_warning'))->toBe(1);
});

/**
 * ⚠️ The warning says what is true and stops there. It does NOT tell her she may leave for free:
 * that is on her booking as `driverDelay`, and a notification announcing an entitlement reads as
 * advice to use it.
 */
it('does not tell her to leave', function () {
    sweepAt($this->tripId, 5);

    $warning = Notification::query()
        ->where('user_id', $this->pax->id)
        ->where('type', 'commute_not_started_warning')
        ->sole();

    expect($warning->body)->not->toContain('free')
        ->and($warning->body)->not->toContain('بدون')
        ->and(array_keys($warning->data))->not->toContain('driverDelay');
});

/**
 * +10: v5's "violation/escalation", as a durable stamp.
 *
 * ⚠️ And NOT a reliability event — see the next test for why.
 */
it('stamps a violation at ten minutes', function () {
    expect(sweepAt($this->tripId, 10)['violations'])->toBe(1);

    expect(ScheduledTrip::query()->whereKey($this->tripId)->sole()->delay_violation_at)
        ->not->toBeNull();

    // Once.
    expect(sweepAt($this->tripId, 11)['violations'])->toBe(0);
});

/**
 * 🔴 A morning nobody had booked harms nobody, so it costs nothing on the score.
 *
 * This is why the violation rung carries no reliability event: the cost is per passenger, recorded
 * at expiry. A flat penalty here would charge a driver for not driving an empty Thursday — and
 * would charge four penalties for one failure on a day with three riders.
 */
it('costs nothing on a day nobody had booked', function () {
    $empty = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->whereKeyNot($this->tripId)
        ->orderBy('trip_date')
        ->firstOrFail();

    sweepAt($empty->getKey(), 25);

    expect(ScheduledTrip::query()->whereKey($empty->getKey())->sole()->status)
        ->toBe(ScheduledTripStatus::Expired)
        // The day is written off and her record is untouched.
        ->and(ReliabilityEvent::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Expiry
|--------------------------------------------------------------------------
*/

/**
 * 🔴 +20, and everything it has to get right at once.
 */
it('writes the morning off at twenty minutes', function () {
    expect(sweepAt($this->tripId, 20)['expired'])->toBe(1);

    $trip = ScheduledTrip::query()->whereKey($this->tripId)->sole();
    $booking = Booking::query()->whereKey($this->bookingId)->sole();

    expect($trip->status)->toBe(ScheduledTripStatus::Expired)
        // Nobody decided this; the day simply did not happen. Not `cancelled`.
        ->and($trip->delay_violation_at)->not->toBeNull()
        /*
         * The same status a woman who gave up and pressed the button herself gets. One status so
         * the consequences cannot drift apart; the reason says which happened.
         */
        ->and($booking->status)->toBe(BookingStatus::ExitedDueToDriverDelay)
        ->and($booking->cancelled_reason)->toBe('trip_expired')
        ->and((int) $booking->cancellation_fee_piastres)->toBe(0);

    /*
     * 🔴 And the record of who was left waiting. A booking says the seat ended; only the attendance
     * row says somebody stood there.
     */
    expect(Attendance::query()->whereKey($this->bookingId)->sole()->status)
        ->toBe(AttendanceStatus::DriverNoShow);

    expect(countNotifications($this->pax->id, 'commute_day_expired'))->toBe(1);
});

it('records it against the driver, once per passenger left waiting', function () {
    sweepAt($this->tripId, 20);

    $event = ReliabilityEvent::query()->sole();

    expect($event->user_id)->toBe($this->driver->id)
        ->and($event->kind)->toBe(ReliabilityEventKind::DriverLateCausedExit)
        ->and($event->booking_id)->toBe($this->bookingId);
});

/**
 * 🔴 A woman who had already given up and pressed the button herself must not cost the driver a
 * second event for the same seat.
 */
it('does not double-count somebody who already left', function () {
    runLeavingIn($this->tripId, -1);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/exit-without-fee")->assertOk();

    sweepAt($this->tripId, 20);

    expect(ReliabilityEvent::query()->count())->toBe(1)
        // Her booking keeps the reason SHE caused, not the sweep's.
        ->and(Booking::query()->whereKey($this->bookingId)->sole()->cancelled_reason)->toBe('not_started');
});

it('expires once and leaves the day alone afterwards', function () {
    sweepAt($this->tripId, 20);

    expect(sweepAt($this->tripId, 30)['expired'])->toBe(0)
        ->and(countNotifications($this->pax->id, 'commute_day_expired'))->toBe(1)
        ->and(ReliabilityEvent::query()->count())->toBe(1);
});

/**
 * ⚠️ A day nothing ever swept — a cron that was off, a deploy gap — is written off quietly rather
 * than generating a reminder and a warning about a morning three weeks gone.
 */
it('writes off a long-forgotten morning without reminding anybody', function () {
    $counts = sweepAt($this->tripId, 60 * 24 * 3);

    expect($counts['expired'])->toBe(1)
        ->and($counts['reminded'])->toBe(0)
        ->and($counts['warned'])->toBe(0)
        ->and(countNotifications($this->driver->id, 'commute_not_started_reminder'))->toBe(0)
        ->and(countNotifications($this->pax->id, 'commute_not_started_warning'))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| What starting does
|--------------------------------------------------------------------------
*/

/**
 * 🔴 v5: *"If started at +12m, remains IN_PROGRESS; existing violation remains."*
 *
 * Neither half needs code. The sweep only looks at days that have not started, so starting removes
 * the day from its reach; and nothing clears a stamp, so the violation she already earned stays.
 */
it('leaves a run that set off late alone, and keeps the violation it earned', function () {
    sweepAt($this->tripId, 12);

    $stamped = ScheduledTrip::query()->whereKey($this->tripId)->sole()->delay_violation_at;

    expect($stamped)->not->toBeNull();

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/start")->assertStatus(201);

    // Well past the expiry rung, and nothing happens.
    expect(sweepAt($this->tripId, 25)['expired'])->toBe(0);

    $trip = ScheduledTrip::query()->whereKey($this->tripId)->sole();

    expect($trip->status)->not->toBe(ScheduledTripStatus::Expired)
        ->and($trip->delay_violation_at?->toIso8601String())->toBe($stamped->toIso8601String())
        ->and(Booking::query()->whereKey($this->bookingId)->sole()->status)
        ->toBe(BookingStatus::Confirmed);
});

it('ignores a day the driver cancelled herself', function () {
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/cancel", ['reason' => 'Car trouble'])
        ->assertOk();

    expect(sweepAt($this->tripId, 25)['expired'])->toBe(0);

    expect(ScheduledTrip::query()->whereKey($this->tripId)->sole()->status)
        ->toBe(ScheduledTripStatus::Cancelled);
});

/**
 * The command the scheduler runs is wired to the Action, which is the only thing that makes any of
 * the above happen in production.
 */
it('runs from the scheduled command', function () {
    runLeavingIn($this->tripId, -20);

    test()->artisan('trips:sweep-delays')->assertExitCode(0);

    expect(ScheduledTrip::query()->whereKey($this->tripId)->sole()->status)
        ->toBe(ScheduledTripStatus::Expired);
});
