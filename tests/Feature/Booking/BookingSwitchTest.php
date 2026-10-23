<?php

use App\Domains\Booking\Actions\OfferSeatToWaitlistAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingEvent;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Support\Facades\Storage;

/**
 * Feature 03 v3: a passenger moves from one commute to another in one atomic step —
 * "**atomic switch must never create two overlapping confirmed bookings**" — and:
 *
 *   - `VOLUNTARY_SWITCH` follows the normal cancellation policy
 *   - `DRIVER_DELAY_SWITCH` carries **no passenger cancellation fee**
 *   - during confirmation she is asked whether to rejoin the old driver's waiting list; if yes the
 *     backend re-adds her **without the old driver's approval**
 *
 * Built on the seat-offer path rather than a separate endpoint: the offer already proves the new
 * driver approved her, and it already holds the lock that allocates the seat, so the switch is
 * atomic for free.
 */
beforeEach(function () {
    Storage::fake('documents');

    // Her current commute, with a confirmed seat on it.
    $this->oldDriver = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->oldCommuteId = readyCommute($this->oldDriver, Vehicle::sole()->id);
    test()->withToken($this->oldDriver)
        ->postJson("/api/v1/commutes/{$this->oldCommuteId}/publish")->assertOk();

    $this->oldTrip = ScheduledTrip::query()
        ->where('commute_offer_id', $this->oldCommuteId)
        ->orderBy('trip_date')->firstOrFail();

    $this->paxToken = verifiedPassenger('01112223344');

    $oldRequest = requestSeat($this->paxToken, $this->oldCommuteId, [
        'scheduledTripId' => $this->oldTrip->id,
    ])->assertStatus(201)->json('data.id');

    $this->oldBookingId = approveSeat($this->oldDriver, $oldRequest);

    // A different driver's commute, on which she is waitlisted and then offered a seat.
    $this->newDriver = approvedDriver(phone: '01055556666', devicePublicId: 'driver-2', seed: 2);

    $newVehicle = Vehicle::query()
        ->whereNot('id', ScheduledTrip::findOrFail($this->oldTrip->id)->commuteOffer->vehicle_id)
        ->sole();

    $this->newCommuteId = readyCommute($this->newDriver, $newVehicle->id);
    test()->withToken($this->newDriver)
        ->postJson("/api/v1/commutes/{$this->newCommuteId}/publish")->assertOk();

    $this->newTrip = ScheduledTrip::query()
        ->where('commute_offer_id', $this->newCommuteId)
        ->orderBy('trip_date')->firstOrFail();
});

/**
 * Puts her on the new commute's waiting list and offers her the seat, which is the state a switch
 * starts from.
 */
function offerOnNewCommute(string $paxToken, string $driverToken, string $commuteId, string $tripId): SeatOffer
{
    $requestId = requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data.id');

    test()->withToken($driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")->assertOk();

    // Free a seat so the fan-out runs.
    $trip = ScheduledTrip::findOrFail($tripId);
    $trip->forceFill(['seats_taken' => 0])->save();

    app(OfferSeatToWaitlistAction::class)->forTrip($trip, 1);

    return SeatOffer::query()->where('seat_request_id', $requestId)->sole();
}

/**
 * 🔴 The guarantee v3 states absolutely: never two overlapping confirmed bookings. Structural here
 * rather than checked after the fact — both sides are one transaction, so there is no instant at
 * which both are confirmed.
 */
it('moves her seat in one step, leaving exactly one confirmed booking', function () {
    $offer = offerOnNewCommute($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->id}/accept", [
            'switchFromBookingId' => $this->oldBookingId,
            'switchReason' => 'voluntary',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'CONFIRMED');

    $passengerId = Booking::findOrFail($this->oldBookingId)->passenger_user_id;

    $confirmed = Booking::query()
        ->where('passenger_user_id', $passengerId)
        ->where('status', BookingStatus::Confirmed->value)
        ->get();

    expect($confirmed)->toHaveCount(1)
        ->and($confirmed->first()->scheduled_trip_id)->toBe($this->newTrip->id)
        // The old one is released, and its seat went back to the old commute.
        ->and(Booking::findOrFail($this->oldBookingId)->status)
        ->toBe(BookingStatus::CancelledByPassenger)
        ->and($this->oldTrip->refresh()->seats_taken)->toBe(0);
});

/**
 * 🔴 v3: "`DRIVER_DELAY_SWITCH` and driver-fault exit carry **no passenger cancellation fee**" —
 * and it is recorded as cancelled by the DRIVER, which is what keeps her lateness off the
 * passenger's own record.
 */
it('charges nothing and blames the driver when the switch is her fault', function () {
    $offer = offerOnNewCommute($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->id}/accept", [
            'switchFromBookingId' => $this->oldBookingId,
            'switchReason' => 'driver_delay',
        ])->assertOk();

    $old = Booking::findOrFail($this->oldBookingId);

    expect($old->status)->toBe(BookingStatus::CancelledByDriver)
        ->and((int) $old->cancellation_fee_piastres)->toBe(0);
});

it('applies the normal policy to a voluntary switch', function () {
    $offer = offerOnNewCommute($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->id}/accept", [
            'switchFromBookingId' => $this->oldBookingId,
            'switchReason' => 'voluntary',
        ])->assertOk();

    $old = Booking::findOrFail($this->oldBookingId);

    expect($old->status)->toBe(BookingStatus::CancelledByPassenger)
        ->and((int) $old->cancellation_fee_piastres)
        ->toBe((int) config('rafeeq.booking.cancellation_fee_piastres'));
});

/**
 * The two bookings are linked where support can find it, without a column that would have to be
 * kept in step.
 */
it('records which booking replaced which', function () {
    $offer = offerOnNewCommute($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    $newBookingId = test()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->id}/accept", [
            'switchFromBookingId' => $this->oldBookingId,
            'switchReason' => 'voluntary',
        ])->assertOk()->json('data.id');

    $event = BookingEvent::query()
        ->where('booking_id', $this->oldBookingId)
        ->where('event_type', 'cancelled')
        ->latest('created_at')
        ->firstOrFail();

    expect($event->metadata['switched_to_booking_id'] ?? null)->toBe($newBookingId)
        ->and($event->metadata['switch_reason'] ?? null)->toBe('voluntary');
});

/**
 * 🔴 v3: "If yes, backend re-adds automatically **without old driver's approval**." Not a loophole
 * — she already held a seat in that car, so consent was given and has not been withdrawn.
 *
 * And it reserves nothing: "no guarantee old recurring seat can be reclaimed."
 */
it('rejoins the old waiting list on request, without asking that driver again', function () {
    $offer = offerOnNewCommute($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->id}/accept", [
            'switchFromBookingId' => $this->oldBookingId,
            'switchReason' => 'driver_delay',
            'joinOldWaitlist' => true,
        ])->assertOk();

    $rejoined = SeatRequest::query()
        ->where('commute_offer_id', $this->oldCommuteId)
        ->where('status', SeatRequestStatus::Waitlisted->value)
        ->get();

    expect($rejoined)->toHaveCount(1)
        // Scoped to the day she left, because that is the day she wanted.
        ->and($rejoined->first()->scheduled_trip_id)->toBe($this->oldTrip->id)
        // Nothing is reserved for her — no seat, just a place on the list.
        ->and($rejoined->first()->waitlist_position)->toBe(1);
});

it('does not rejoin the old list unless she asked', function () {
    $offer = offerOnNewCommute($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->id}/accept", [
            'switchFromBookingId' => $this->oldBookingId,
            'switchReason' => 'voluntary',
        ])->assertOk();

    expect(SeatRequest::query()
        ->where('commute_offer_id', $this->oldCommuteId)
        ->where('status', SeatRequestStatus::Waitlisted->value)
        ->count())->toBe(0);
});

/**
 * 🔒 The booking she is leaving has to be her own. Without this, a passenger could pass somebody
 * else's booking id and have their seat released on her behalf.
 */
it('refuses to release a booking that is not hers', function () {
    $offer = offerOnNewCommute($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    $stranger = verifiedPassenger('01222229999', device: 'pax-stranger');
    $strangerRequest = requestSeat($stranger, $this->oldCommuteId, [
        'scheduledTripId' => $this->oldTrip->id,
    ])->assertStatus(201)->json('data.id');
    $strangerBooking = approveSeat($this->oldDriver, $strangerRequest);

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->id}/accept", [
            'switchFromBookingId' => $strangerBooking,
            'switchReason' => 'voluntary',
        ])
        ->assertStatus(404);

    // Nothing moved, on either side.
    expect(Booking::findOrFail($strangerBooking)->status)->toBe(BookingStatus::Confirmed)
        ->and(Booking::findOrFail($this->oldBookingId)->status)->toBe(BookingStatus::Confirmed);
});

/**
 * A plain acceptance still works with no switch fields at all — the whole point of making them
 * optional.
 */
it('still takes a seat with an empty body', function () {
    $offer = offerOnNewCommute($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->id}/accept", [])
        ->assertOk();

    // She now holds both, which is legitimate: they are different days on different commutes.
    expect(Booking::findOrFail($this->oldBookingId)->status)->toBe(BookingStatus::Confirmed);
});
