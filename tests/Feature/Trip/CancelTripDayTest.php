<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingEvent;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * "Cancel today" (screen 23, Screen Map §8.6, decided 2026-10-06): the driver calls off one day and
 * the rest of the commute stands.
 *
 * 🔴 The failure this exists to prevent is a driver who cannot make it on a Tuesday and has no way
 * to say so except pausing the whole commute — or simply not turning up, while somebody waits at a
 * gate at seven in the morning.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');

    $days = ScheduledTrip::query()->orderBy('trip_date')->limit(2)->get();
    $this->tripId = $days[0]->id;
    $this->otherDayId = $days[1]->id;

    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id'));
    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $this->pax = User::query()->where('phone_e164', '+201223339999')->sole();
});

function cancelDay(string $token, string $tripId, array $payload = [])
{
    return test()->withToken($token)->postJson("/api/v1/trips/{$tripId}/cancel", $payload);
}

it('cancels the day, ends every booking on it, and frees the seats', function () {
    cancelDay($this->driverToken, $this->tripId, ['reason' => 'Car in the garage'])
        ->assertOk()
        ->assertJsonPath('data.status', 'CANCELLED')
        ->assertJsonPath('data.seatsTaken', 0);

    $booking = Booking::query()->findOrFail($this->bookingId);

    expect($booking->status)->toBe(BookingStatus::CancelledByDriver)
        ->and($booking->cancellation_fee_piastres)->toBe(0)
        ->and(ScheduledTrip::query()->findOrFail($this->tripId)->status)->toBe(ScheduledTripStatus::Cancelled);
});

it('leaves every other day of the commute alone', function () {
    $otherBooking = approveSeat($this->driverToken, requestSeat(
        verifiedPassenger('01555556666', device: 'pax-3'), $this->commuteId, ['scheduledTripId' => $this->otherDayId],
    )->json('data.id'));

    cancelDay($this->driverToken, $this->tripId)->assertOk();

    expect(ScheduledTrip::query()->findOrFail($this->otherDayId)->status)->toBe(ScheduledTripStatus::Scheduled)
        ->and(Booking::query()->findOrFail($otherBooking)->status)->toBe(BookingStatus::Confirmed);
});

/**
 * 🔒 Told by name and date — never the reason, which may be personal and shows on a lock screen.
 */
it('tells every passenger on the day, without the reason', function () {
    cancelDay($this->driverToken, $this->tripId, ['reason' => 'Hospital appointment'])->assertOk();

    $note = Notification::query()->where('user_id', $this->pax->id)->where('type', 'trip_day_cancelled')->where('channel', 'in_app')->sole();

    expect($note->title.$note->body)->not->toContain('Hospital')
        ->and($note->data)->toBe(['tripId' => $this->tripId, 'bookingId' => $this->bookingId]);
});

it('records the driver as the one who cancelled — the record reliability will count', function () {
    cancelDay($this->driverToken, $this->tripId)->assertOk();

    $event = BookingEvent::query()->where('booking_id', $this->bookingId)->where('event_type', 'cancelled')->sole();

    expect($event->actor_type->value)->toBe('driver')
        ->and($event->actor_id)->toBe($this->driver->id);
});

it('expires a one-day request for that date that nobody answered', function () {
    $requestId = requestSeat(verifiedPassenger('01555556666', device: 'pax-3'), $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id');

    cancelDay($this->driverToken, $this->tripId)->assertOk();

    expect(SeatRequest::query()->findOrFail($requestId)->status)->toBe(SeatRequestStatus::Expired);
});

it('cannot be cancelled twice, or once the run has started', function () {
    cancelDay($this->driverToken, $this->tripId)->assertOk();
    cancelDay($this->driverToken, $this->tripId)->assertStatus(409)->assertJsonPath('error.code', 'TRIP_NOT_CANCELLABLE');

    runLeavingIn($this->otherDayId, 10, dayOffset: 60);
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->otherDayId}/start")->assertStatus(201);

    cancelDay($this->driverToken, $this->otherDayId)->assertStatus(409)->assertJsonPath('error.code', 'TRIP_NOT_CANCELLABLE');
});

it('is the driver’s alone — anybody else gets a 404', function () {
    cancelDay($this->paxToken, $this->tripId)->assertNotFound();
    cancelDay(approvedDriver(phone: '01099998888', devicePublicId: 'driver-2', seed: 2), $this->tripId)->assertNotFound();

    expect(ScheduledTrip::query()->findOrFail($this->tripId)->status)->toBe(ScheduledTripStatus::Scheduled);
});

it('stays cancelled: it cannot be booked, started, or regenerated', function () {
    cancelDay($this->driverToken, $this->tripId)->assertOk();

    requestSeat(verifiedPassenger('01555556666', device: 'pax-3'), $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(409);

    $this->artisan('commutes:generate-trips')->assertSuccessful();

    expect(ScheduledTrip::query()->findOrFail($this->tripId)->status)->toBe(ScheduledTripStatus::Cancelled)
        ->and(ScheduledTrip::query()->where('commute_offer_id', ScheduledTrip::query()->findOrFail($this->tripId)->commute_offer_id)
            ->where('trip_date', ScheduledTrip::query()->findOrFail($this->tripId)->trip_date)->count())->toBe(1);
});
