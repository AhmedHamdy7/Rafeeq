<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $this->bookingId = approveSeat(
        $this->driverToken,
        requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201)->json('data.id'),
    );

    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $this->pax = User::query()->where('phone_e164', '+201223339999')->sole();
});

function patrolAs(string $token)
{
    return test()->flushHeaders()->withToken($token);
}

function patrolDroppedOff(string $driverToken, string $tripId, string $bookingId): void
{
    underway($driverToken, $tripId);
    checkIn($driverToken, $tripId, ['bookingId' => $bookingId])->assertOk();
    patrolAs($driverToken)->postJson("/api/v1/trips/{$tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    patrolAs($driverToken)->postJson("/api/v1/trips/{$tripId}/drop-off", ['bookingId' => $bookingId])->assertOk();
}

it('B-1: ends a no-showed booking as NO_SHOW when the run completes', function () {
    underway($this->driverToken, $this->tripId);
    arrivedAndWaited($this->driverToken, $this->tripId, $this->bookingId);

    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])->assertOk();
    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    patrolAs($this->paxToken)->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()->assertJsonPath('data.status', 'NO_SHOW');

    expect(collect(patrolAs($this->paxToken)->getJson('/api/v1/my-bookings?scope=past')->assertOk()->json('data'))
        ->firstWhere('id', $this->bookingId)['status'])->toBe('NO_SHOW');

    rateBooking($this->paxToken, $this->bookingId)->assertStatus(422)->assertJsonPath('error.code', 'TRIP_NOT_RATEABLE');

    expect(Notification::query()->where('user_id', $this->pax->id)->where('type', 'rating_due')->exists())->toBeFalse();
});

it('B-1: keeps a no-show as NO_SHOW when the run is called off', function () {
    underway($this->driverToken, $this->tripId);
    arrivedAndWaited($this->driverToken, $this->tripId, $this->bookingId);

    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])->assertOk();
    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/cancel-active", ['reason' => 'other'])->assertOk();

    expect(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::NoShow);
});

it('B-3: accepts a recurring request after the trial day has ended', function () {
    patrolDroppedOff($this->driverToken, $this->tripId, $this->bookingId);
    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    $recurring = patrolAs($this->paxToken)->postJson("/api/v1/commutes/{$this->commuteId}/seat-requests", [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
        'seats' => 1,
        'meetingPreference' => 'gate',
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road, Rehab exit'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
        'paymentType' => 'cash',
        'agreedToRules' => true,
    ])->assertStatus(201);

    expect($recurring->json('data.status'))->toBe('PENDING')
        ->and(SeatRequest::query()->where('commitment', 'trial')->sole()->status)->toBe(SeatRequestStatus::Ended);
});

it('B-3: still refuses a second request while the trial day is ahead', function () {
    requestSeat($this->paxToken, $this->commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(409)->assertJsonPath('error.code', 'BOOKING_ALREADY_REQUESTED');
});

it('B-4: ends her live-share link at her drop-off, and the driver\'s when the run completes', function () {
    underway($this->driverToken, $this->tripId);
    $sessionId = TripSession::sole()->id;

    patrolAs($this->paxToken)->postJson("/api/v1/trips/{$this->tripId}/live-share")->assertSuccessful();
    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/live-share")->assertSuccessful();

    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();
    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/drop-off", ['bookingId' => $this->bookingId])->assertOk();

    $hers = patrolAs($this->paxToken)->getJson("/api/v1/safety/live-shares?tripSessionId={$sessionId}")->assertOk()->json('data.0');

    expect($hers['isActive'])->toBeFalse()
        ->and($hers['endedAt'])->not->toBeNull()
        ->and(patrolAs($this->driverToken)->getJson("/api/v1/safety/live-shares?tripSessionId={$sessionId}")->json('data.0.isActive'))->toBeTrue();

    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    expect(patrolAs($this->driverToken)->getJson("/api/v1/safety/live-shares?tripSessionId={$sessionId}")->json('data.0.isActive'))->toBeFalse();
});

it('B-7: puts a fee she already owes on a booking made afterwards', function () {
    owe($this->pax->id, 3_750, side: DebtSide::Passenger, type: DebtEntryType::Fee);

    $booking = Booking::query()->findOrFail($this->bookingId);
    expect($booking->fee_line_piastres)->toBe(3_750);

    patrolAs($this->paxToken)->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    $nextTrip = ScheduledTrip::query()->where('id', '!=', $this->tripId)->orderBy('departure_at')->firstOrFail();
    $nextBooking = approveSeat(
        $this->driverToken,
        requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $nextTrip->id])->assertStatus(201)->json('data.id'),
    );

    $upcoming = collect(patrolAs($this->paxToken)->getJson('/api/v1/my-bookings?scope=upcoming')->assertOk()->json('data'));

    expect($upcoming->firstWhere('id', $nextBooking)['feeLinePiastres'])->toBe(3_750)
        ->and($upcoming->firstWhere('id', $nextBooking)['feeLineReason'])->not->toBeNull()
        ->and(Booking::query()->findOrFail($this->bookingId)->fee_line_piastres)->toBe(0);
});

it('B-11: asks the driver to rate the riders when the run completes after everybody was dropped off', function () {
    patrolDroppedOff($this->driverToken, $this->tripId, $this->bookingId);
    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    $notice = Notification::query()
        ->where('user_id', $this->driver->id)
        ->where('type', 'rating_due_riders')
        ->where('channel', 'in_app')
        ->sole();

    expect($notice->data['tripId'])->toBe($this->tripId)
        ->and($notice->data['riderCount'])->toBe('1');
});

it('B-13: suspends her once a fee riding on her next booking stays unpaid for 14 days', function () {
    config(['rafeeq.testing.api' => true]);
    $nextBooking = $this->bookingId;

    owe($this->pax->id, 12_500, side: DebtSide::Passenger, type: DebtEntryType::Fee);

    expect(Booking::query()->findOrFail($nextBooking)->fee_line_piastres)->toBeGreaterThan(0);

    $before = patrolAs($this->paxToken)->getJson('/api/v1/account/debt')->assertOk()->json('data.passenger');
    expect($before['oldestUnpaidAt'])->not->toBeNull();

    patrolAs($this->paxToken)->postJson('/api/v1/testing/debt/age', ['days' => 15, 'side' => 'passenger'])->assertOk();

    $after = patrolAs($this->paxToken)->getJson('/api/v1/account/debt')->assertOk()->json('data.passenger');

    expect($after['suspended'])->toBeTrue()
        ->and(Booking::query()->findOrFail($nextBooking)->status)->not->toBe(BookingStatus::Confirmed);

    requestSeat($this->paxToken, $this->commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertJsonPath('error.code', 'RIDING_SUSPENDED_FOR_DEBT');
});

it('completes a run with two passengers aboard and a no-show (no lazy loading in strict mode)', function () {
    $otherToken = verifiedPassenger('01223338888', device: 'pax-9');
    $otherBooking = approveSeat(
        $this->driverToken,
        requestSeat($otherToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201)->json('data.id'),
    );

    underway($this->driverToken, $this->tripId);
    arrivedAndWaited($this->driverToken, $this->tripId, $this->bookingId);
    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])->assertOk();
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $otherBooking])->assertOk();
    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    patrolAs($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    expect(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::NoShow)
        ->and(Booking::query()->findOrFail($otherBooking)->status)->toBe(BookingStatus::Completed);
});

it('B-13: starts her 14 days at her oldest fee even when the ledger lines do not cover the balance', function () {
    config(['rafeeq.testing.api' => true]);

    owe($this->pax->id, 12_500, side: DebtSide::Passenger, type: DebtEntryType::Fee);
    \App\Domains\Payment\Models\DebtAccount::query()
        ->where('user_id', $this->pax->id)->where('side', 'passenger')
        ->update(['balance_piastres' => 15_000]);

    patrolAs($this->paxToken)->postJson('/api/v1/testing/debt/age', ['days' => 15, 'side' => 'passenger'])->assertOk();

    $after = patrolAs($this->paxToken)->getJson('/api/v1/account/debt')->assertOk()->json('data.passenger');

    expect($after['oldestUnpaidAt'])->not->toBeNull()
        ->and($after['suspended'])->toBeTrue();
});
