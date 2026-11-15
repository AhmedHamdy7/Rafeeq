<?php

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Notification\Models\Notification;

beforeEach(function () {
    config(['rafeeq.testing.api' => true]);

    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['seatsTotal' => 1]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
    $this->riderToken = verifiedPassenger('01112223344', device: 'pax-1');
    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->riderToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));
});

function waitingOn(string $driverToken, string $commuteId, string $tripId, string $phone, string $device): array
{
    $token = verifiedPassenger($phone, device: $device);
    $requestId = requestSeat($token, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")->assertOk();

    return ['token' => $token, 'requestId' => $requestId];
}

function asUser(string $token)
{
    return test()->flushHeaders()->withToken($token);
}

it('never sends the driver’s typed reason to the passenger', function () {
    asUser($this->driverToken)
        ->postJson("/api/v1/driver/bookings/{$this->bookingId}/cancel", ['reason' => 'E13: the car is in service'])
        ->assertOk();

    $hers = asUser($this->riderToken)->getJson("/api/v1/bookings/{$this->bookingId}")->assertOk();

    expect($hers->json('data.cancelledReason'))->toBe('removed_by_driver')
        ->and($hers->json('data'))->not->toHaveKey('cancelledNote')
        ->and(json_encode(asUser($this->riderToken)->getJson('/api/v1/my-bookings?scope=past')->json()))
        ->not->toContain('E13');

    $his = asUser($this->driverToken)->getJson("/api/v1/bookings/{$this->bookingId}")->assertOk();

    expect($his->json('data.cancelledReason'))->toBe('removed_by_driver')
        ->and($his->json('data.cancelledNote'))->toBe('E13: the car is in service');
});

it('gives a pause its code and tells the passenger nothing is aboard', function () {
    asUser($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/pause")->assertOk();

    $hers = asUser($this->riderToken)->getJson("/api/v1/bookings/{$this->bookingId}")->assertOk();

    expect($hers->json('data.cancelledReason'))->toBe('commute_paused')
        ->and($hers->json('data.tripCancelReason'))->toBeNull()
        ->and($hers->json('data.wasAboard'))->toBeNull()
        ->and($hers->json('data.feeLinePiastres'))->toBe(0);
});

it('codes a cancelled day', function () {
    asUser($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/cancel", ['reason' => 'Family matter'])->assertOk();

    expect(asUser($this->riderToken)->getJson("/api/v1/bookings/{$this->bookingId}")->json('data.cancelledReason'))
        ->toBe('day_cancelled');
});

it('says whether a booking came from a trial or a recurring request', function () {
    expect(asUser($this->riderToken)->getJson("/api/v1/bookings/{$this->bookingId}")->json('data.commitment'))
        ->toBe('trial')
        ->and(asUser($this->riderToken)->getJson('/api/v1/my-bookings')->json('data.0.commitment'))
        ->toBe('trial');
});

it('reads one seat offer by id, with where it came from and why it ended', function () {
    $waiter = waitingOn($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    asUser($this->riderToken)->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    $offerId = SeatOffer::query()->where('seat_request_id', $waiter['requestId'])->sole()->id;

    asUser($waiter['token'])->getJson("/api/v1/seat-offers/{$offerId}")
        ->assertOk()
        ->assertJsonPath('data.id', $offerId)
        ->assertJsonPath('data.status', 'OPEN')
        ->assertJsonPath('data.source', 'seat_freed')
        ->assertJsonPath('data.isPreviousPassenger', false)
        ->assertJsonPath('data.commitment', 'trial')
        ->assertJsonPath('data.offerGroupId', null)
        ->assertJsonPath('data.closedReason', null)
        ->assertJsonPath('data.stillWaiting', true);

    asUser($this->riderToken)->getJson("/api/v1/seat-offers/{$offerId}")->assertNotFound();

    asUser($waiter['token'])->postJson("/api/v1/testing/seat-offers/{$offerId}/close", ['status' => 'INELIGIBLE'])
        ->assertOk()
        ->assertJsonPath('data.status', 'INELIGIBLE')
        ->assertJsonPath('data.closedReason', 'car_passed')
        ->assertJsonPath('data.passedPointLabel', 'Ring Road, Rehab exit')
        ->assertJsonPath('data.stillWaiting', true);

    asUser($waiter['token'])->getJson("/api/v1/seat-offers/{$offerId}")
        ->assertOk()
        ->assertJsonPath('data.closedReason', 'car_passed');
});

it('closes an open offer when she leaves the waiting list', function () {
    $waiter = waitingOn($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    asUser($this->riderToken)->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    asUser($waiter['token'])->deleteJson("/api/v1/seat-requests/{$waiter['requestId']}")->assertOk();

    $offer = SeatOffer::query()->where('seat_request_id', $waiter['requestId'])->sole();

    asUser($waiter['token'])->getJson("/api/v1/seat-offers/{$offer->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'CLOSED')
        ->assertJsonPath('data.closedReason', 'request_withdrawn')
        ->assertJsonPath('data.stillWaiting', false);
});

it('groups the days a driver offered on resume and accepts them together', function () {
    $waiter = waitingOn($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    asUser($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/pause")->assertOk();
    asUser($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/resume")->assertOk();
    asUser($this->driverToken)->postJson("/api/v1/driver/commutes/{$this->commuteId}/waitlist/offers", [
        'seatRequestIds' => [$waiter['requestId']],
    ])->assertOk();

    $row = asUser($waiter['token'])->getJson('/api/v1/seat-offers?status=OPEN')->assertOk()->json('data.0');

    expect($row['source'])->toBe('driver_resume')
        ->and($row['offerGroupId'])->not->toBeNull()
        ->and($row['daysMask'])->toBeInt();

    asUser($waiter['token'])->postJson("/api/v1/seat-offers/groups/{$row['offerGroupId']}/accept")
        ->assertOk()
        ->assertJsonPath('meta.offers.0.status', 'ACCEPTED');

    asUser($this->riderToken)->postJson("/api/v1/seat-offers/groups/{$row['offerGroupId']}/accept")->assertNotFound();
});

it('asks for a seat from alternatives without the points', function () {
    asUser($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/cancel")->assertOk();

    $nextTrip = ScheduledTrip::query()->whereKeyNot($this->tripId)->orderBy('trip_date')->firstOrFail()->id;

    $requestId = asUser($this->riderToken)->postJson("/api/v1/commutes/{$this->commuteId}/seat-requests", [
        'commitment' => 'trial',
        'scheduledTripId' => $nextTrip,
        'seats' => 1,
        'paymentType' => 'cash',
        'agreedToRules' => true,
        'alternativeForBookingId' => $this->bookingId,
    ])->assertStatus(201)->json('data.id');

    $request = SeatRequest::findOrFail($requestId);
    $booking = Booking::findOrFail($this->bookingId);

    expect($request->requested_pickup_label)->toBe($booking->pickup_label)
        ->and($request->requested_dropoff_label)->toBe($booking->dropoff_label);
});

it('refuses an alternative for somebody else’s booking', function () {
    $other = verifiedPassenger('01155667788', device: 'pax-2');

    asUser($other)->postJson("/api/v1/commutes/{$this->commuteId}/seat-requests", [
        'commitment' => 'trial',
        'scheduledTripId' => $this->tripId,
        'paymentType' => 'cash',
        'agreedToRules' => true,
        'alternativeForBookingId' => $this->bookingId,
    ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['alternativeForBookingId']]]);
});

it('names the request and the change when a change clears her waiting list, and lets her reapply', function () {
    CommuteWeekdaySchedule::query()->where('commute_offer_id', $this->commuteId)->update(['departure_is_computed' => false]);

    $waiter = waitingOn($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    $proposalId = asUser($this->driverToken)->postJson("/api/v1/driver/commutes/{$this->commuteId}/change-proposals", [
        'scheduledTripId' => $this->tripId,
        'scope' => 'all_future_on_this_weekday',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    asUser($this->riderToken)->getJson("/api/v1/change-proposals?bookingId={$this->bookingId}")
        ->assertOk()->assertJsonCount(1, 'data');

    asUser($this->riderToken)->postJson("/api/v1/change-proposals/{$proposalId}/respond", ['response' => 'accepted'])->assertOk();

    $data = Notification::query()->where('type', 'waitlist_cleared_by_change')->sole()->data;

    expect(Notification::query()->where('type', 'waitlist_cleared_by_change')->sole()->body)->not->toContain(':name')
        ->and($data['seatRequestId'])->toBe($waiter['requestId'])
        ->and($data['changeProposalId'])->toBe($proposalId);

    asUser($waiter['token'])->getJson("/api/v1/seat-requests/{$waiter['requestId']}")
        ->assertOk()->assertJsonPath('data.status', 'TERMS_CHANGED');

    asUser($this->riderToken)->getJson("/api/v1/change-proposals/{$proposalId}")
        ->assertOk()
        ->assertJsonPath('data.id', $proposalId)
        ->assertJsonPath('data.bookingId', $this->bookingId)
        ->assertJsonPath('data.myResponse', 'ACCEPTED');

    asUser($waiter['token'])->getJson("/api/v1/change-proposals/{$proposalId}")->assertNotFound();

    requestSeat($waiter['token'], $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201);

    expect(SeatRequest::findOrFail($waiter['requestId'])->status)->toBe(SeatRequestStatus::TermsChanged);
});

it('writes a test notification for the caller’s own records only', function () {
    asUser($this->riderToken)->postJson('/api/v1/testing/notifications', [
        'type' => 'seat_offered',
        'data' => ['bookingId' => $this->bookingId],
    ])->assertStatus(201);

    $other = verifiedPassenger('01155667788', device: 'pax-2');

    asUser($other)->postJson('/api/v1/testing/notifications', [
        'type' => 'seat_offered',
        'data' => ['bookingId' => $this->bookingId],
    ])->assertStatus(422);

    asUser($other)->postJson('/api/v1/testing/notifications', ['type' => 'otp_code'])->assertStatus(422);
});

it('tells an aboard passenger and a waiting one apart when the run is stopped', function () {
    underway($this->driverToken, $this->tripId);

    asUser($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/check-in", ['bookingId' => $this->bookingId])->assertOk();
    asUser($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/cancel-active", [
        'reason' => 'breakdown',
        'note' => 'Engine gave out on the Mehwar',
    ])->assertOk();

    $hers = asUser($this->riderToken)->getJson("/api/v1/bookings/{$this->bookingId}")->assertOk();

    expect($hers->json('data.status'))->toBe('ENDED_EARLY')
        ->and($hers->json('data.cancelledReason'))->toBe('active_trip_cancelled')
        ->and($hers->json('data.tripCancelReason'))->toBe('breakdown')
        ->and($hers->json('data.wasAboard'))->toBeTrue()
        ->and($hers->json('data'))->not->toHaveKey('cancelledNote')
        ->and(json_encode($hers->json()))->not->toContain('Engine gave out');

    expect(asUser($this->driverToken)->getJson("/api/v1/bookings/{$this->bookingId}")->json('data.cancelledNote'))
        ->toBe('breakdown: Engine gave out on the Mehwar');
});

it('says she was not aboard when the run stopped before reaching her', function () {
    underway($this->driverToken, $this->tripId);

    asUser($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/cancel-active", ['reason' => 'other'])->assertOk();

    $hers = asUser($this->riderToken)->getJson("/api/v1/bookings/{$this->bookingId}")->assertOk();

    expect($hers->json('data.status'))->toBe('CANCELLED_BY_DRIVER')
        ->and($hers->json('data.cancelledReason'))->toBe('active_trip_cancelled')
        ->and($hers->json('data.tripCancelReason'))->toBe('other')
        ->and($hers->json('data.wasAboard'))->toBeFalse();
});
