<?php

use App\Domains\Booking\Actions\RespondToSeatRequestAction;
use App\Domains\Booking\Enums\PointSource;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Notification\Models\Notification;

/**
 * The driver's third answer to a seat request, and the passenger's answer back (Feature 03 v5 §2).
 *
 * > *"The driver must review them and explicitly **Accept**, **Reject**, or **Counteroffer** a
 * > different pickup/drop-off. A counteroffer requires passenger's explicit in-app acceptance;
 * > **chat is not consent**. Only after confirmation do the passenger's **agreed** pickup/drop-off
 * > become committed route stops."*
 *
 * 🔴 "Chat is not consent" is why this is a structured exchange and not a conversation. Two people
 * can agree a street corner in a chat thread in ten seconds and nothing in the system would know:
 * the itinerary would still send the driver to the original point, the attendance geofence would
 * still measure against it, and a dispute about a missed pickup would be settled against a record
 * that disagreed with what both of them remembered agreeing.
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
});

function counteroffer(string $driverToken, string $requestId, array $payload)
{
    return test()->flushHeaders()->withToken($driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/counteroffer", $payload);
}

it('records the driver’s proposed pickup and leaves the agreed points alone', function () {
    counteroffer($this->driverToken, $this->requestId, [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3300, 'label' => 'Petrol station, not the side street'],
        'note' => 'I can’t get into that street at 7am.',
    ])->assertOk()->assertJsonPath('data.status', 'COUNTEROFFERED');

    $request = SeatRequest::findOrFail($this->requestId);

    expect($request->status)->toBe(SeatRequestStatus::Counteroffered)
        ->and((float) $request->counteroffer_pickup_lat)->toBe(30.0630)
        ->and($request->counteroffer_note)->toBe('I can’t get into that street at 7am.')
        /*
         * 🔴 Nothing committed moved. This is the heart of it: until she accepts, the agreed point
         * is still the one the passenger chose.
         */
        ->and((float) $request->requested_pickup_lat)->toBe(30.0624)
        // And the end the driver did not touch carries no proposal at all, so the passenger is
        // shown one decision rather than being asked to re-agree to her own choice.
        ->and($request->counteroffer_dropoff_lat)->toBeNull();

    // She is told it is a question, not an approval and not a refusal.
    expect(Notification::query()->where('type', 'seat_counteroffered')->count())->toBe(1)
        ->and(Notification::query()->where('type', 'seat_approved')->count())->toBe(0)
        ->and(Notification::query()->where('type', 'seat_declined')->count())->toBe(0);
});

it('creates no booking while the counteroffer is unanswered', function () {
    counteroffer($this->driverToken, $this->requestId, [
        'dropoff' => ['lat' => 30.0690, 'lng' => 31.1300, 'label' => 'Before the bridge'],
    ])->assertOk();

    expect(Booking::query()->count())->toBe(0)
        ->and(ScheduledTrip::findOrFail($this->tripId)->seats_taken)->toBe(0);
});

/**
 * 🔴 Accepting it confirms the seat, and the agreed point becomes the DRIVER's proposal — recorded
 * as such, because a dispute about somebody waiting at the wrong corner turns on whose idea the
 * corner was.
 */
it('confirms the seat when she accepts, with the driver’s point as the agreed one', function () {
    counteroffer($this->driverToken, $this->requestId, [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3300, 'label' => 'Petrol station'],
    ])->assertOk();

    $bookingId = test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-requests/{$this->requestId}/counteroffer/accept")
        ->assertStatus(201)
        ->json('data.bookings.0.id');

    $booking = Booking::findOrFail($bookingId);
    $request = SeatRequest::findOrFail($this->requestId);

    expect($request->status)->toBe(SeatRequestStatus::Approved)
        ->and((float) $request->requested_pickup_lat)->toBe(30.0630)
        ->and($request->requested_pickup_source)->toBe(PointSource::DriverCounteroffer)
        ->and($request->counteroffer_answered_at)->not->toBeNull()
        // 🔴 The booking's immutable snapshot is the AGREED point, not what she first asked for.
        ->and((float) $booking->pickup_lat)->toBe(30.0630)
        ->and($booking->pickup_label)->toBe('Petrol station')
        // The end nobody renegotiated is untouched.
        ->and((float) $booking->dropoff_lat)->toBe(30.0684);
});

/**
 * 🔴 And the agreed point is what the driver is actually sent to. The counteroffer would be
 * decorative if the navigation itinerary still pointed at the original.
 */
it('sends the driver to the point they agreed on', function () {
    counteroffer($this->driverToken, $this->requestId, [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3300, 'label' => 'Petrol station'],
    ])->assertOk();

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-requests/{$this->requestId}/counteroffer/accept")->assertStatus(201);

    $stops = test()->flushHeaders()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/commute-occurrences/{$this->tripId}/navigation-route")
        ->assertOk()->json('data.stops');

    $pickup = array_values(array_filter($stops, fn (array $s): bool => $s['kind'] === 'PASSENGER_PICKUP'));

    expect($pickup[0]['lat'])->toBe(30.063)
        ->and($pickup[0]['label'])->toBe('Petrol station');
});

/**
 * 🔴 Her counteroffer promised a MEETING PLACE, never that a seat would still be free. Somebody
 * else can take the last one while the conversation is open, and the honest answer then is a
 * refusal rather than an extra passenger in a full car.
 */
it('refuses the acceptance when the seat went while she was deciding', function () {
    counteroffer($this->driverToken, $this->requestId, [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3300, 'label' => 'Petrol station'],
    ])->assertOk();

    // The car fills up in the meantime: two seats, two other riders.
    foreach ([['01155667788', 'pax-2'], ['01166778899', 'pax-3']] as [$phone, $device]) {
        $other = verifiedPassenger($phone, device: $device);
        $id = requestSeat($other, $this->commuteId, ['scheduledTripId' => $this->tripId])
            ->assertStatus(201)->json('data.id');
        approveSeat($this->driverToken, $id);
    }

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-requests/{$this->requestId}/counteroffer/accept")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_UNAVAILABLE');

    // And her request survives for a day when a seat does free up, rather than being burned.
    expect(SeatRequest::findOrFail($this->requestId)->status)->toBe(SeatRequestStatus::Counteroffered);
});

/**
 * 🔴 Declining gets its OWN status, and that is a decision about whose record it lands on.
 * `rejected` would inflate the driver's refusal rate for something the passenger decided;
 * `withdrawn` would read as backing out before an answer, when an answer is what she is answering.
 */
it('closes the request under its own status when she declines', function () {
    counteroffer($this->driverToken, $this->requestId, [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3300, 'label' => 'Petrol station'],
    ])->assertOk();

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-requests/{$this->requestId}/counteroffer/decline")
        ->assertOk()
        ->assertJsonPath('data.status', 'COUNTEROFFER_DECLINED');

    expect(SeatRequest::findOrFail($this->requestId)->status)
        ->toBe(SeatRequestStatus::CounterofferDeclined)
        ->and(Booking::query()->count())->toBe(0);

    // The driver learns the conversation ended.
    expect(Notification::query()->where('type', 'seat_counteroffer_declined')->count())->toBe(1);
});

/**
 * 🔴 A counteroffer is an OPEN request. Without this, she could file a second one on the same
 * commute while the first conversation was live and the driver would see two of her — which is why
 * `active_request_key` was rebuilt to include the status.
 */
it('still counts as her one open request on that commute', function () {
    counteroffer($this->driverToken, $this->requestId, [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3300, 'label' => 'Petrol station'],
    ])->assertOk();

    requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_ALREADY_REQUESTED');
});

/**
 * A body that moves nothing is refused rather than recorded. A counteroffer the passenger has to
 * accept in order to get exactly what she asked for is a round trip that changes nothing and costs
 * her a decision.
 */
it('refuses a counteroffer that proposes nothing', function () {
    counteroffer($this->driverToken, $this->requestId, ['note' => 'Just checking'])
        ->assertStatus(422);

    // And one that echoes her own coordinates straight back.
    counteroffer($this->driverToken, $this->requestId, [
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road, Rehab exit'],
    ])->assertStatus(422);

    expect(SeatRequest::findOrFail($this->requestId)->status)->toBe(SeatRequestStatus::Pending);
});

/**
 * The proposed pair is judged by the same rules as a passenger's own. A driver can propose
 * something unworkable too, and the check protects the riders she has already promised.
 */
it('refuses a counteroffer that would run the journey backwards', function () {
    counteroffer($this->driverToken, $this->requestId, [
        // Collected past where she is being set down.
        'pickup' => ['lat' => 30.0700, 'lng' => 31.0500, 'label' => 'Past the drop-off'],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_POINTS_OUT_OF_ORDER');
});

it('refuses a counteroffer whose detour exceeds her own limit', function () {
    counteroffer($this->driverToken, $this->requestId, [
        'pickup' => ['lat' => 30.6000, 'lng' => 31.4000, 'label' => 'Far north'],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PICKUP_DETOUR_TOO_LONG');
});

/**
 * 🔒 Only the driver whose commute it is may counteroffer, and only the passenger it belongs to may
 * answer. A 404 either way — a 403 would confirm the id exists, and a seat request names who is
 * trying to ride with whom.
 */
it('hides the exchange from everybody it does not belong to', function () {
    $stranger = verifiedPassenger('01199887766', device: 'pax-9');

    counteroffer($stranger, $this->requestId, [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3300, 'label' => 'Nowhere'],
    ])->assertStatus(404);

    counteroffer($this->driverToken, $this->requestId, [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3300, 'label' => 'Petrol station'],
    ])->assertOk();

    test()->flushHeaders()->withToken($stranger)
        ->postJson("/api/v1/seat-requests/{$this->requestId}/counteroffer/accept")
        ->assertStatus(404);

    expect(SeatRequest::findOrFail($this->requestId)->status)->toBe(SeatRequestStatus::Counteroffered);
});

/**
 * A counteroffer nobody answers closes, like every other open request: it holds her one open slot
 * for that commute, and the driver is holding a place for a conversation that ended.
 */
it('expires a counteroffer nobody answered', function () {
    counteroffer($this->driverToken, $this->requestId, [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3300, 'label' => 'Petrol station'],
    ])->assertOk();

    SeatRequest::query()->whereKey($this->requestId)->update(['expires_at' => now()->subMinute()]);

    RespondToSeatRequestAction::expireOverdue();

    expect(SeatRequest::findOrFail($this->requestId)->status)->toBe(SeatRequestStatus::Expired);
});
