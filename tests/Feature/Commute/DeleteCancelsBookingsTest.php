<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\DisplacementReason;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Notification\Models\Notification;

/**
 * Deleting a commute cancels every upcoming day, booked ones included (Feature 03 C-8, COM-35,
 * decision C6).
 *
 * 🔴 It used to cancel only the days nobody had taken. Passengers kept confirmed seats on a commute
 * that no longer existed, nobody told them, and they found out at the kerb.
 */
beforeEach(function () {
    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['seatsTotal' => 2]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $this->paxToken = verifiedPassenger('01112223344');
    $this->requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $this->bookingId = approveSeat($this->driverToken, $this->requestId);
});

function deleteCommute(string $driverToken, string $commuteId): void
{
    test()->withToken($driverToken)->deleteJson("/api/v1/commutes/{$commuteId}")
        ->assertOk()
        ->assertJsonPath('data.status', 'ARCHIVED');
}

it('cancels a booked upcoming day and the seat on it', function () {
    deleteCommute($this->driverToken, $this->commuteId);

    $booking = Booking::findOrFail($this->bookingId);

    expect($booking->status)->toBe(BookingStatus::CancelledByDriver)
        ->and($booking->cancelled_reason)->toBe(DisplacementReason::CommuteDeleted->value)
        ->and(ScheduledTrip::findOrFail($this->tripId)->status)->toBe(ScheduledTripStatus::Cancelled);

    // Every upcoming day, not only the ones nobody had taken.
    expect(ScheduledTrip::query()
        ->where('departure_at', '>', now())
        ->where('status', '!=', ScheduledTripStatus::Cancelled->value)
        ->count())->toBe(0);
});

it('tells each passenger once, and does not put her on a waiting list', function () {
    deleteCommute($this->driverToken, $this->commuteId);

    expect(Notification::query()->where('type', 'commute_deleted_seat_cancelled')->count())->toBe(1)
        ->and(SeatRequest::findOrFail($this->requestId)->status)->toBe(SeatRequestStatus::CommuteArchived)
        ->and(SeatRequest::findOrFail($this->requestId)->waitlist_position)->toBeNull();
});

/**
 * Requests never expire now (R-4), so a deleted commute has to close its own open questions.
 */
it('closes every open request and waiting-list place on it', function () {
    $askingToken = verifiedPassenger('01155667788', device: 'pax-2');
    $asking = requestSeat($askingToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $waitingToken = verifiedPassenger('01155667799', device: 'pax-3');
    $waiting = requestSeat($waitingToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$waiting}/waitlist")->assertOk();

    deleteCommute($this->driverToken, $this->commuteId);

    test()->withToken($askingToken)->getJson('/api/v1/seat-requests')
        ->assertOk()->assertJsonPath('data.0.status', 'COMMUTE_ARCHIVED');

    expect(SeatRequest::findOrFail($waiting)->status)->toBe(SeatRequestStatus::CommuteArchived)
        ->and(SeatRequest::findOrFail($asking)->status)->toBe(SeatRequestStatus::CommuteArchived);
});

it('leaves a run that has already begun alone', function () {
    ScheduledTrip::query()->whereKey($this->tripId)
        ->update(['status' => ScheduledTripStatus::InProgress->value]);

    deleteCommute($this->driverToken, $this->commuteId);

    expect(Booking::findOrFail($this->bookingId)->status)->toBe(BookingStatus::Confirmed)
        ->and(ScheduledTrip::findOrFail($this->tripId)->status)->toBe(ScheduledTripStatus::InProgress);
});
