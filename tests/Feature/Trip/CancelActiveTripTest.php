<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingEvent;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Storage;

/**
 * "Cancel Active Trip": the driver stops a run that has already set off (Feature 03 v5 §123–129).
 *
 * > *"**Pause Commute cannot cancel an already-started trip.** Driver must use **Cancel Active
 * > Trip** with applicable fees and Reliability impact; emergency appeal available."*
 *
 * 🔴 Before this existed there was no lawful way to end a journey that had gone wrong. "Cancel
 * today" refuses once the run has begun — correctly — so a driver whose car broke down on the
 * Mehwar had the choice of driving it anyway or abandoning people with the system still insisting
 * the trip was in progress and their seats confirmed.
 *
 * 🔴 The whole design is in one distinction: the woman who was in the car and the woman still
 * waiting at her gate are not in the same situation, do not get the same booking status, and do not
 * get the same message.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();

    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $this->bookingId = seat($this->driverToken, $this->paxToken, $this->commuteId, $this->trip->id);
});

/**
 * One confirmed seat on the day.
 *
 * 🔴 Every one of these is taken BEFORE the run sets off, and `setOff()` is a separate step for
 * that reason: a request made to a car already moving is a different feature with its own rules
 * (`PickupReachability`), and three tests here were first written to book
 * a second passenger after departure and were refused `PICKUP_UNREACHABLE` — correctly. Seating
 * everybody first is not test plumbing, it is the only order a morning actually happens in.
 */
function seat(string $driverToken, string $paxToken, string $commuteId, string $tripId): string
{
    return approveSeat(
        $driverToken,
        requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])->json('data.id'),
    );
}

/** The car leaves. Nothing here may be cancelled as an active trip until it has. */
function setOff(string $driverToken, string $tripId): void
{
    underway($driverToken, $tripId);
}

function cancelActive(string $token, string $tripId, array $payload = ['reason' => 'Engine gave out on the Mehwar'])
{
    return test()->withToken($token)->postJson("/api/v1/trips/{$tripId}/cancel-active", $payload);
}

/** Puts her in the car, which is what every "aboard" branch below turns on. */
function board(string $driverToken, string $tripId, string $bookingId): void
{
    checkIn($driverToken, $tripId, ['bookingId' => $bookingId])->assertOk();
}

it('stops the run and says how many were aboard and how many were waiting', function () {
    setOff($this->driverToken, $this->trip->id);

    cancelActive($this->driverToken, $this->trip->id)
        ->assertOk()
        ->assertJsonPath('data.status', 'CANCELLED')
        ->assertJsonPath('data.seatsTaken', 0)
        ->assertJsonPath('meta.endedEarly', 0)
        ->assertJsonPath('meta.cancelled', 1);

    expect(TripSession::query()->where('scheduled_trip_id', $this->trip->id)->sole()->current_status)
        ->toBe(TripSessionStatus::Cancelled)
        ->and(ScheduledTrip::query()->findOrFail($this->trip->id)->status)
        ->toBe(ScheduledTripStatus::Cancelled);
});

it('cancels the seat of somebody who was never collected', function () {
    setOff($this->driverToken, $this->trip->id);

    cancelActive($this->driverToken, $this->trip->id)->assertOk();

    $booking = Booking::query()->findOrFail($this->bookingId);

    expect($booking->status)->toBe(BookingStatus::CancelledByDriver)
        ->and($booking->cancellation_fee_piastres)->toBe(0);
});

/**
 * 🔴 The case the whole file exists for (v5 §128). Recording this as a cancellation would erase a
 * journey she actually made — and the day money moves, it would erase what she owes for it.
 */
it('ends the journey early for somebody who was in the car, and keeps that she travelled', function () {
    setOff($this->driverToken, $this->trip->id);
    board($this->driverToken, $this->trip->id, $this->bookingId);

    cancelActive($this->driverToken, $this->trip->id)
        ->assertOk()
        ->assertJsonPath('meta.endedEarly', 1)
        ->assertJsonPath('meta.cancelled', 0);

    $booking = Booking::query()->findOrFail($this->bookingId);
    $attendance = Attendance::query()->whereKey($this->bookingId)->sole();

    expect($booking->status)->toBe(BookingStatus::EndedEarly)
        ->and($booking->cancellation_fee_piastres)->toBe(0)
        // She was in the car and the record still says so.
        ->and($attendance->status)->toBe(AttendanceStatus::Present)
        ->and($attendance->checked_out_at)->not->toBeNull();
});

it('settles the attendance of somebody nobody decided about, rather than leaving it pending', function () {
    setOff($this->driverToken, $this->trip->id);

    cancelActive($this->driverToken, $this->trip->id)->assertOk();

    expect(Attendance::query()->whereKey($this->bookingId)->sole()->status)
        ->toBe(AttendanceStatus::Cancelled);
});

it('charges the driver, not the passenger, as ending a trip for another reason', function () {
    setOff($this->driverToken, $this->trip->id);

    cancelActive($this->driverToken, $this->trip->id)->assertOk();

    $event = ReliabilityEvent::query()->where('booking_id', $this->bookingId)->sole();

    expect($event->kind)->toBe(ReliabilityEventKind::DriverAbandonedActiveTrip)
        ->and($event->user_id)->toBe(Booking::query()->findOrFail($this->bookingId)->driver_profile_id)
        // Snapshotted, so a later config change never rewrites what her record said.
        // Feature 03 files X-3: "ends a trip for other reason" −8 — until F-13's reason list lands,
        // every such ending is weighed as "other reason".
        ->and($event->weight)->toBe(8)
        ->and($event->weight)->toBeGreaterThan((int) config('rafeeq.reliability.weights.driver_cancel_short_notice'));
});

/**
 * Per passenger, not once per run — the same reasoning a cancelled day is recorded by. Stopping a
 * full car has to cost more than stopping an empty one, because the harm is per woman.
 */
it('records it against her once for every passenger whose morning she ended', function () {
    $second = seat(
        $this->driverToken,
        verifiedPassenger('01223338888', device: 'pax-3'),
        $this->commuteId,
        $this->trip->id,
    );

    setOff($this->driverToken, $this->trip->id);

    cancelActive($this->driverToken, $this->trip->id)->assertOk();

    expect(ReliabilityEvent::query()
        ->where('kind', ReliabilityEventKind::DriverAbandonedActiveTrip->value)
        ->count())->toBe(2)
        ->and(Booking::query()->findOrFail($second)->status)->toBe(BookingStatus::CancelledByDriver);
});

/**
 * 🔴 Two messages for one act, because they are opposite news. A woman at a gate needs "no car is
 * coming". A woman just put out at the roadside knows that already — hers has to say she owes
 * nothing and point somewhere.
 */
it('tells the woman waiting something different from the woman it set down', function () {
    // Both seats taken before anybody moves; only then does one of them get in the car.
    seat($this->driverToken, verifiedPassenger('01223337777', device: 'pax-4'), $this->commuteId, $this->trip->id);

    setOff($this->driverToken, $this->trip->id);
    board($this->driverToken, $this->trip->id, $this->bookingId);

    cancelActive($this->driverToken, $this->trip->id)->assertOk();

    $kinds = Notification::query()
        ->whereIn('type', ['active_trip_cancelled', 'active_trip_cancelled_aboard'])
        ->pluck('type')
        ->sort()
        ->values()
        ->all();

    expect($kinds)->toBe(['active_trip_cancelled', 'active_trip_cancelled_aboard']);
});

/**
 * 🔒 Her typed reason stays out of the push. It may be written in the minute after a crash, and it
 * is not something to send to five lock screens — support reads it on the booking.
 */
it('keeps her reason on the record and out of the message', function () {
    setOff($this->driverToken, $this->trip->id);

    cancelActive($this->driverToken, $this->trip->id, ['reason' => 'My son was taken to hospital'])->assertOk();

    $notice = Notification::query()->where('type', 'active_trip_cancelled')->sole();

    expect(Booking::query()->findOrFail($this->bookingId)->cancelled_reason)->toBe('My son was taken to hospital')
        ->and(json_encode($notice->getAttributes()))->not->toContain('hospital');
});

it('demands a reason, because an appeal reviewed against a blank one is a reviewer guessing', function () {
    setOff($this->driverToken, $this->trip->id);

    cancelActive($this->driverToken, $this->trip->id, [])
        ->assertStatus(422)
        ->assertJsonPath('error.fields.reason.0', fn (string $message) => $message !== '');

    expect(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::Confirmed);
});

/**
 * 🔴 The run has to have left. A `preparing` run is "cancel today", which costs her less — routing
 * it here through a client retry would charge her an abandonment for a morning nobody had left home
 * for.
 */
it('refuses a run that has not set off, and names where it is', function () {
    $other = ScheduledTrip::query()->where('id', '!=', $this->trip->id)->orderBy('trip_date')->firstOrFail();

    /*
     * Only `departure_at` moves. `runLeavingIn` also moves `trip_date`, and every generated day of
     * one commute already holds a date — `(commute_offer_id, trip_date)` is unique — so shifting
     * this one onto another day's date is a duplicate-key error rather than a test. The start
     * window is measured against `departure_at` and nothing else.
     */
    ScheduledTrip::query()->whereKey($other->id)->update(['departure_at' => now()->addMinutes(10)]);

    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$other->id}/start")->assertStatus(201);

    cancelActive($this->driverToken, $other->id)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_NOT_CANCELLABLE')
        ->assertJsonPath('error.fields.status.0', 'PREPARING');
});

it('refuses a run that never started at all', function () {
    $other = ScheduledTrip::query()->where('id', '!=', $this->trip->id)->orderBy('trip_date')->firstOrFail();

    cancelActive($this->driverToken, $other->id)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_NOT_STARTED');
});

it('is the driver’s alone — a passenger on it gets a 404', function () {
    setOff($this->driverToken, $this->trip->id);

    cancelActive($this->paxToken, $this->trip->id)->assertNotFound();

    expect(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::Confirmed);
});

/**
 * 🔴 The freed seats are not offered to anybody. A seat on a run that has stopped is not a seat,
 * and the offer would go out to the waiting list for a car going nowhere.
 */
it('offers the emptied seats to nobody', function () {
    $waiting = requestSeat(
        verifiedPassenger('01223336666', device: 'pax-5'),
        $this->commuteId,
        ['scheduledTripId' => $this->trip->id],
    )->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$waiting}/waitlist")->assertOk();

    setOff($this->driverToken, $this->trip->id);

    cancelActive($this->driverToken, $this->trip->id)->assertOk();

    expect(SeatOffer::query()->count())->toBe(0);
});

it('names the driver as the actor, and says whether each passenger was aboard', function () {
    setOff($this->driverToken, $this->trip->id);
    board($this->driverToken, $this->trip->id, $this->bookingId);

    cancelActive($this->driverToken, $this->trip->id)->assertOk();

    $event = BookingEvent::query()
        ->where('booking_id', $this->bookingId)
        ->whereJsonContains('metadata->active_trip_cancelled', true)
        ->sole();

    expect($event->metadata['was_aboard'])->toBeTrue()
        ->and($event->actor_id)->toBe(User::query()->where('phone_e164', '+201012345678')->sole()->id);
});

it('cannot be done twice', function () {
    setOff($this->driverToken, $this->trip->id);

    cancelActive($this->driverToken, $this->trip->id)->assertOk();

    cancelActive($this->driverToken, $this->trip->id)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.status.0', 'CANCELLED');
});

/**
 * 🔴 The hole this closed, and it was reachable from the public API. `AdvanceTripRequest` validates
 * `status` with `Rule::enum`, which accepts `cancelled`, and the state machine used to allow
 * `preparing → cancelled` and `en_route → cancelled`. So one ordinary request marked the run and
 * the day cancelled while every booking on it still read CONFIRMED, the seats still counted as
 * taken, and not one passenger was told.
 */
it('refuses to cancel a run through the plain status endpoint, and says where to go instead', function () {
    setOff($this->driverToken, $this->trip->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->trip->id}/status", ['status' => 'CANCELLED'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_INVALID_TRANSITION')
        ->assertJsonPath('error.fields.use.0', 'POST /v1/trips/{trip}/cancel-active');

    expect(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::Confirmed)
        ->and(ScheduledTrip::query()->findOrFail($this->trip->id)->status)->not->toBe(ScheduledTripStatus::Cancelled);
});

it('does not list cancelling among the steps a run could take next', function () {
    setOff($this->driverToken, $this->trip->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->trip->id}/status", ['status' => 'PREPARING'])
        ->assertStatus(409)
        ->assertJsonMissing(['CANCELLED']);
});
