<?php

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * The waiting list, and what happens when a seat comes free (Master Plan §889:
 * "Waitlist + الترتيب").
 *
 * 🔴 The rule the whole feature turns on: a freed seat promotes the next person into
 * the DRIVER'S INBOX, it does not book them. In Rafeeq the driver decides who rides
 * with them, and seating a stranger automatically because somebody else cancelled
 * would put a person in their car without them ever saying yes.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->first();

    // One passenger seated, and then the day is filled so everybody after her waits.
    $this->riderToken = verifiedPassenger('01112223344', device: 'pax-1');

    $requestId = requestSeat($this->riderToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    $this->bookingId = approveSeat($this->driverToken, $requestId);

    $this->trip->forceFill(['seats_taken' => 3])->save();
});

/**
 * Joins the queue and returns the position it was given.
 */
function joinWaitlist(string $phone, string $device, string $commuteId, string $tripId): array
{
    $token = verifiedPassenger($phone, device: $device);

    $data = requestSeat($token, $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data');

    return ['token' => $token, 'id' => $data['id'], 'position' => $data['waitlistPosition']];
}

it('gives each waiting passenger their own place in the queue', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    expect($first['position'])->toBe(1)
        ->and($second['position'])->toBe(2);
});

/**
 * 🔴 The heart of it: the seat reaches the person who was waiting, as a question for
 * the driver rather than a booking.
 */
it('promotes the first in line when a seat comes free, without booking them', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel", ['reason' => 'Plans changed'])
        ->assertOk();

    $promoted = SeatRequest::query()->whereKey($first['id'])->sole();

    expect($promoted->status)->toBe(SeatRequestStatus::Pending)
        // No longer waiting, so no place in a queue.
        ->and($promoted->waitlist_position)->toBeNull()
        // NOT booked. The driver still decides.
        ->and(Booking::query()
            ->where('passenger_user_id', $promoted->passenger_user_id)
            ->exists())->toBeFalse();

    // And they are now in the driver's inbox to be answered.
    test()->withToken($this->driverToken)->getJson('/api/v1/driver/seat-requests')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    // The person behind them moves up rather than being left at number two.
    expect(SeatRequest::query()->whereKey($second['id'])->sole()->waitlist_position)->toBe(1);
});

it('promotes only one person per freed seat', function () {
    joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);
    joinWaitlist('01222220003', 'pax-4', $this->commuteId, $this->trip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    // One seat freed, one promotion. Promoting the queue would leave three hopeful
    // people competing for one seat and two of them finding out by refusal.
    expect(SeatRequest::query()->where('status', SeatRequestStatus::Pending->value)->count())->toBe(1)
        ->and(SeatRequest::query()->where('status', SeatRequestStatus::Waitlisted->value)->count())->toBe(2);
});

/**
 * The clock restarts on promotion. The 48 hours were spent waiting for a seat, not
 * waiting for an answer, and expiring a request the driver has only just been shown
 * would refuse it on their behalf.
 */
it('gives a promoted request a fresh window to be answered in', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);

    /*
     * The request is aged in the database rather than by travelling the clock: an
     * access token does not survive 47 hours, so a time-travelling version of this
     * test would be asserting about the session instead of the queue.
     */
    SeatRequest::query()->whereKey($first['id'])->update(['expires_at' => now()->addHour()]);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    $promoted = SeatRequest::query()->whereKey($first['id'])->sole();

    expect($promoted->status)->toBe(SeatRequestStatus::Pending)
        // The full window again, not the hour that was left of the old one.
        ->and($promoted->expires_at->isAfter(now()->addHours(
            (int) config('rafeeq.booking.request_expiry_hours') - 1
        )))->toBeTrue();
});

it('does not promote somebody waiting for a different day', function () {
    $otherTrip = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->where('id', '!=', $this->trip->id)
        ->orderBy('trip_date')
        ->first();

    $otherTrip->forceFill(['seats_taken' => 3])->save();

    $waiting = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $otherTrip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    // A seat freeing on Sunday is no help to somebody waiting for Monday.
    expect(SeatRequest::query()->whereKey($waiting['id'])->sole()->status)
        ->toBe(SeatRequestStatus::Waitlisted);
});

it('refuses to lengthen a queue that is already a false hope', function () {
    config(['rafeeq.booking.max_waitlist_size' => 2]);

    joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    $third = verifiedPassenger('01222220003', device: 'pax-4');

    requestSeat($third, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_WAITLIST_FULL');
});

/**
 * Somebody leaving the middle of the queue must not leave everybody behind them
 * reading a position that is further back than where they stand.
 */
it('closes the gap when a waiting passenger withdraws', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);
    $third = joinWaitlist('01222220003', 'pax-4', $this->commuteId, $this->trip->id);

    test()->withToken($second['token'])->deleteJson("/api/v1/seat-requests/{$second['id']}")->assertOk();

    expect(SeatRequest::query()->whereKey($first['id'])->sole()->waitlist_position)->toBe(1)
        ->and(SeatRequest::query()->whereKey($third['id'])->sole()->waitlist_position)->toBe(2);
});

/**
 * The position is assigned from the highest one in use, not from how many people are
 * waiting: with three at 1, 2, 3, a count-based number would hand the next arrival
 * position 3 — the place somebody else is already standing in.
 */
it('never hands out a position somebody else already holds', function () {
    joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);
    joinWaitlist('01222220003', 'pax-4', $this->commuteId, $this->trip->id);

    // Removed straight in the database, so no renumbering runs — the raw shape the
    // count-based version got wrong.
    SeatRequest::query()->whereKey($second['id'])->update([
        'status' => SeatRequestStatus::Withdrawn->value,
        'waitlist_position' => null,
    ]);

    $fourth = joinWaitlist('01222220004', 'pax-5', $this->commuteId, $this->trip->id);

    $positions = SeatRequest::query()
        ->where('status', SeatRequestStatus::Waitlisted->value)
        ->pluck('waitlist_position');

    expect($fourth['position'])->toBe(4)
        ->and($positions->count())->toBe($positions->unique()->count());
});

it('does not promote an expired request', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    // The first one gave up on it long ago.
    SeatRequest::query()->whereKey($first['id'])->update(['expires_at' => now()->subHour()]);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    expect(SeatRequest::query()->whereKey($first['id'])->sole()->status)
        ->toBe(SeatRequestStatus::Waitlisted)
        ->and(SeatRequest::query()->whereKey($second['id'])->sole()->status)
        ->toBe(SeatRequestStatus::Pending);
});

it('does not promote somebody who needs more seats than came free', function () {
    $token = verifiedPassenger('01222220001', device: 'pax-2');

    // Two seats wanted; one comes free.
    $request = requestSeat($token, $this->commuteId, [
        'scheduledTripId' => $this->trip->id,
        'seats' => 2,
    ])->assertStatus(201)->json('data');

    expect($request['status'])->toBe('WAITLISTED');

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    expect(SeatRequest::query()->whereKey($request['id'])->sole()->status)
        ->toBe(SeatRequestStatus::Waitlisted);
});

it('lets the driver approve a promoted request like any other', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    $bookingId = approveSeat($this->driverToken, $first['id']);

    $booking = Booking::query()->whereKey($bookingId)->sole();

    expect($booking->passenger_user_id)
        ->toBe(User::query()->where('phone_e164', '+201222220001')->sole()->id)
        ->and($this->trip->refresh()->seats_taken)->toBe(3);
});

/**
 * 🔴 The driver's third answer — screen 28's `Waitlist` button.
 *
 * Not a yes and not a no: "I would take you, but not this week." Before it, the driver's
 * only alternative to approving was declining, so a full week meant refusing somebody they
 * had already judged suitable — and that person's only way back was to ask again.
 */
it('lets the driver park a request instead of refusing it', function () {
    // A day with room, so this is the driver choosing rather than the day being full.
    $this->trip->forceFill(['seats_taken' => 0])->save();

    $token = verifiedPassenger('01222220009', device: 'pax-9');

    $requestId = requestSeat($token, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'PENDING')
        ->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist", ['note' => 'Full this week — first next week.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'WAITLISTED')
        // A place in the queue, not a null: the position is what the passenger is shown.
        ->assertJsonPath('data.waitlistPosition', 1)
        ->assertJsonPath('data.responseNote', 'Full this week — first next week.');

    // Parked, not booked.
    expect(Booking::query()->where('seat_request_id', $requestId)->exists())->toBeFalse();
});

it('gives a parked request a fresh window, since the driver just answered', function () {
    $this->trip->forceFill(['seats_taken' => 0])->save();

    $token = verifiedPassenger('01222220009', device: 'pax-9');

    $requestId = requestSeat($token, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    SeatRequest::query()->whereKey($requestId)->update(['expires_at' => now()->addHour()]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")->assertOk();

    // The 48 hours it had were for the driver to answer, and they did.
    expect(SeatRequest::query()->whereKey($requestId)->sole()->expires_at
        ->isAfter(now()->addHours((int) config('rafeeq.booking.request_expiry_hours') - 1)))->toBeTrue();
});

it('refuses to park a request that is already answered', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);

    // Already waiting — there is nowhere to park it.
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$first['id']}/waitlist")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_NOT_PENDING');
});

it('refuses to park a request on somebody else commute', function () {
    $this->trip->forceFill(['seats_taken' => 0])->save();

    $token = verifiedPassenger('01222220009', device: 'pax-9');

    $requestId = requestSeat($token, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223339999', devicePublicId: 'driver-2', seed: 2);

    // 404, not 403: a 403 would confirm the request exists.
    test()->withToken($otherDriver)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")
        ->assertStatus(404);
});

it('refuses to park anybody once the queue is full', function () {
    config(['rafeeq.booking.max_waitlist_size' => 1]);

    joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);

    $this->trip->forceFill(['seats_taken' => 0])->save();

    $token = verifiedPassenger('01222220009', device: 'pax-9');

    $requestId = requestSeat($token, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_WAITLIST_FULL');
});
