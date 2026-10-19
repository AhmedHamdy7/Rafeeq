<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\DisplacementReason;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Notification\Models\Notification;

/**
 * Pausing a commute now cancels the seats on it (Feature 03 v5).
 *
 * 🔴 **This file asserts a reversal.** Pausing used to leave existing bookings alone — the code
 * said so in writing, reading Chapter 4's "paused commutes keep history" as "does not cancel days
 * people have booked". v5 overrides it: *"It cancels ALL future confirmed bookings, not active
 * trip; displaced passengers are notified and automatically added to preserved waiting list.
 * Templates and waitlist persist. Canceled seats never auto-restore."*
 *
 * The old behaviour was the kinder-looking one and the new one is the honest one. A paused commute
 * generates no days and cannot be found; a confirmed seat on it is a woman planning her morning
 * around a lift that will not come, and she would find out when it did not arrive.
 */
beforeEach(function () {
    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['seatsTotal' => 2]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $this->paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $this->requestId = $requestId;
    $this->bookingId = approveSeat($this->driverToken, $requestId);
});

function pauseCommute(string $driverToken, string $commuteId): void
{
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/pause")->assertOk();
}

it('cancels a future confirmed booking and frees the seat', function () {
    pauseCommute($this->driverToken, $this->commuteId);

    expect(Booking::findOrFail($this->bookingId)->status)->toBe(BookingStatus::CancelledByDriver)
        ->and(ScheduledTrip::findOrFail($this->tripId)->seats_taken)->toBe(0);
});

/**
 * 🔴 Cancelled by the DRIVER, not by her. She did nothing, and a passenger's own cancellation
 * history is what a future driver reads when deciding whether to accept her.
 */
it('records the cancellation against the driver with the reason that caused it', function () {
    pauseCommute($this->driverToken, $this->commuteId);

    $booking = Booking::findOrFail($this->bookingId);

    expect($booking->status)->toBe(BookingStatus::CancelledByDriver)
        ->and($booking->cancelled_reason)->toBe(DisplacementReason::CommutePaused->value);
});

/**
 * 🔴 "Displaced passengers are automatically added to preserved waiting list."
 *
 * Her own request row, flipped — not a fresh application. She keeps the terms the driver already
 * approved, and the marker is what lets the driver's waitlist card say **Previous Passenger**.
 */
it('puts the displaced passenger back on the waiting list, marked as displaced', function () {
    pauseCommute($this->driverToken, $this->commuteId);

    $waiting = SeatRequest::findOrFail($this->requestId);

    expect($waiting->status)->toBe(SeatRequestStatus::Waitlisted)
        ->and($waiting->displaced_reason)->toBe(DisplacementReason::CommutePaused->value)
        ->and($waiting->displaced_at)->not->toBeNull()
        ->and($waiting->waitlist_position)->toBe(1)
        // Cleared: a waiting list has no deadline, and an `expires_at` inherited from the approval
        // window would quietly drop her off the list she was just promised.
        ->and($waiting->expires_at)->toBeNull();
});

it('tells her once, naming the reason, and says she is back on the list', function () {
    pauseCommute($this->driverToken, $this->commuteId);

    $told = Notification::query()->where('type', 'commute_paused_seat_cancelled')->get();

    expect($told)->toHaveCount(1)
        ->and($told->first()->body)->toContain('waiting list');
});

/**
 * 🔴 No "a seat just opened up" for a journey that has stopped running.
 *
 * The ordinary cancellation path offers a released seat to everybody waiting. Here that offer would
 * reach the very women who just lost their seats, inviting them back onto a paused commute.
 */
it('does not offer the freed seat to the waiting list', function () {
    // Somebody already waiting behind her, so there is a real candidate for a fan-out to find.
    $waiterToken = verifiedPassenger('01155667788', device: 'pax-2');
    $waiterRequest = requestSeat($waiterToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$waiterRequest}/waitlist")->assertOk();

    pauseCommute($this->driverToken, $this->commuteId);

    expect(SeatOffer::query()->where('status', SeatOfferStatus::Open->value)->count())->toBe(0)
        ->and(Notification::query()->where('type', 'seat_offered')->count())->toBe(0);
});

/**
 * 🔴 v5: "Pause Commute cannot cancel an already-started trip. Driver must use Cancel Active Trip."
 *
 * Somebody sitting in the car is not displaced by a setting.
 */
it('leaves a run that has already begun alone', function () {
    ScheduledTrip::query()->whereKey($this->tripId)
        ->update(['status' => ScheduledTripStatus::InProgress->value]);

    pauseCommute($this->driverToken, $this->commuteId);

    expect(Booking::findOrFail($this->bookingId)->status)->toBe(BookingStatus::Confirmed)
        ->and(SeatRequest::findOrFail($this->requestId)->status)->toBe(SeatRequestStatus::Approved);
});

/**
 * "Templates and waitlist persist." The commute itself survives intact — it is what she resumes.
 */
it('keeps the commute and its generated days', function () {
    $before = ScheduledTrip::query()->where('commute_offer_id', $this->commuteId)->count();

    pauseCommute($this->driverToken, $this->commuteId);

    expect(ScheduledTrip::query()->where('commute_offer_id', $this->commuteId)->count())->toBe($before)
        ->and(ScheduledTrip::findOrFail($this->tripId)->status)->toBe(ScheduledTripStatus::Scheduled);
});

/**
 * 🔴 "Canceled seats never auto-restore." Resuming makes the commute findable again and catches up
 * on the days that went by; it does not hand anybody their seat back, and it does not fan the empty
 * seats out either. That is her decision, made separately.
 */
it('does not restore the seat when she resumes', function () {
    pauseCommute($this->driverToken, $this->commuteId);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/resume")->assertOk();

    expect(Booking::findOrFail($this->bookingId)->status)->toBe(BookingStatus::CancelledByDriver)
        ->and(SeatRequest::findOrFail($this->requestId)->status)->toBe(SeatRequestStatus::Waitlisted)
        // And nothing was offered automatically: v5 makes resume the one case where the driver
        // chooses who gets a seat.
        ->and(SeatOffer::query()->count())->toBe(0);
});
