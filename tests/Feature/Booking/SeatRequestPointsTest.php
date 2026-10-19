<?php

use App\Domains\Booking\Enums\PointSource;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;

/**
 * Every seat request names where she gets in and where she gets out (Feature 03 v5 §2).
 *
 * > *"To request a seat, the passenger MUST choose both a Pickup Point and a Drop-off Point. Each
 * > has map coordinates and label. They cannot be omitted, inferred from the driver's route
 * > endpoints, or silently defaulted."*
 *
 * 🔴 What this replaces. A request used to carry `meetingPreference` — gate, street, landmark or
 * custom — and an optional place id, which is a HINT about what kind of spot she would rather meet
 * at. There was no drop-off at all: it was assumed to be the end of the driver's route, which is
 * exactly the inference v5 forbids. A driver was therefore approving somebody without knowing where
 * to collect her, and carrying her without knowing where she was going.
 *
 * `meetingPreference` survives, because "I'd rather meet at a gate than on a street" is a real and
 * different thing to say. What it no longer does is stand in for a point.
 */
beforeEach(function () {
    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
    $this->paxToken = verifiedPassenger('01112223344');
});

/**
 * 🔴 v5's acceptance test 3: "Passenger attempts request with missing pickup or missing drop-off →
 * server validation error; request not created."
 */
it('refuses a request with no pickup point', function () {
    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'pickup' => null,
    ])->assertStatus(422);

    expect(SeatRequest::query()->count())->toBe(0);
});

it('refuses a request with no drop-off point', function () {
    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'dropoff' => null,
    ])->assertStatus(422);

    expect(SeatRequest::query()->count())->toBe(0);
});

/**
 * 🔴 And it is never silently defaulted. A request that sends neither is refused rather than
 * quietly filled in from the driver's own start and end — which is the inference v5 names.
 */
it('does not fill the points in from the driver’s route endpoints', function () {
    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'pickup' => null,
        'dropoff' => null,
    ])->assertStatus(422);

    expect(SeatRequest::query()->count())->toBe(0);
});

it('stores two custom map pins with their labels and sources', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road, Rehab exit'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
    ])->assertStatus(201)->json('data.id');

    $stored = SeatRequest::findOrFail($requestId);

    expect((float) $stored->requested_pickup_lat)->toBe(30.0624)
        ->and((float) $stored->requested_pickup_lng)->toBe(31.3614)
        ->and($stored->requested_pickup_label)->toBe('Ring Road, Rehab exit')
        ->and($stored->requested_pickup_source)->toBe(PointSource::CustomMap)
        ->and((float) $stored->requested_dropoff_lat)->toBe(30.0684)
        ->and($stored->requested_dropoff_label)->toBe('Mehwar, Sheikh Zayed')
        ->and($stored->requested_dropoff_source)->toBe(PointSource::CustomMap)
        // The spatial column is written too, so geographic queries see the same place the
        // decimals describe.
        ->and($stored->requested_pickup_point?->lat)->toBe(30.0624);
});

/**
 * 🔴 v5's acceptance test 4: "Passenger chooses pickup from driver suggestions and custom drop-off
 * on map → pending request contains both precise points and source types."
 */
it('records a driver suggestion and a custom pin as different sources on one request', function () {
    // A fresh commute, because a route is replaced as a whole and this one advertises a pickup.
    $commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    saveRoute($this->driverToken, $commuteId, [
        'pickups' => [['lat' => 30.0624, 'lng' => 31.3614, 'address' => 'Ring Road, Rehab exit']],
    ])->assertOk();

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $suggestion = CommuteLocation::query()
        ->where('commute_offer_id', $commuteId)
        ->where('type', CommuteLocationType::Pickup->value)
        ->sole();

    $tripId = ScheduledTrip::query()->where('commute_offer_id', $commuteId)->orderBy('trip_date')->firstOrFail()->id;

    $requestId = requestSeat($this->paxToken, $commuteId, [
        'scheduledTripId' => $tripId,
        'pickup' => ['suggestionId' => $suggestion->getKey()],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
    ])->assertStatus(201)->json('data.id');

    $stored = SeatRequest::findOrFail($requestId);

    expect($stored->requested_pickup_source)->toBe(PointSource::DriverSuggestion)
        ->and($stored->requested_pickup_suggestion_id)->toBe($suggestion->getKey())
        // Taken from the driver's own stop, not from anything the client sent.
        ->and((float) $stored->requested_pickup_lat)->toBe(30.0624)
        ->and($stored->requested_pickup_label)->toBe('Ring Road, Rehab exit')
        // And the other end is hers, on the map, marked as such.
        ->and($stored->requested_dropoff_source)->toBe(PointSource::CustomMap)
        ->and($stored->requested_dropoff_suggestion_id)->toBeNull();
});

/**
 * 🔒 A suggestion id is looked up among THIS commute's advertised stops. Without that scope, an id
 * from another driver's commute would pass validation and become a meeting point on a journey its
 * owner never offered.
 */
it('refuses a suggestion that belongs to another commute', function () {
    $otherCommute = readyCommute($this->driverToken, Vehicle::sole()->id);

    saveRoute($this->driverToken, $otherCommute, [
        'pickups' => [['lat' => 30.0624, 'lng' => 31.3614, 'address' => 'Somewhere else']],
    ])->assertOk();

    $theirSuggestion = CommuteLocation::query()
        ->where('commute_offer_id', $otherCommute)
        ->where('type', CommuteLocationType::Pickup->value)
        ->sole();

    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'pickup' => ['suggestionId' => $theirSuggestion->getKey()],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_SUGGESTION_UNKNOWN');

    expect(SeatRequest::query()->count())->toBe(0);
});

/**
 * 🔴 v5 §1: the driver's start and end are "the driver's journey endpoints, NOT automatically
 * passenger pickup/drop-off stops". So they are not choosable as suggestions. Somebody who really
 * wants to meet at the start drops a pin there, and it is recorded as `custom_map` — which is the
 * truth, because the driver never advertised that spot as somewhere she would stop for a stranger.
 */
it('does not treat the driver’s own start as a suggestion she can pick', function () {
    $origin = CommuteLocation::query()
        ->where('commute_offer_id', $this->commuteId)
        ->where('type', CommuteLocationType::Origin->value)
        ->sole();

    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'pickup' => ['suggestionId' => $origin->getKey()],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_SUGGESTION_UNKNOWN');
});

it('refuses a pickup and drop-off at the same place', function () {
    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Here'],
        'dropoff' => ['lat' => 30.06245, 'lng' => 31.36145, 'label' => 'Also here'],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_POINTS_SAME');
});

/**
 * 🔴 v5 §2: "Validate travel direction/order ... server-side", and §3: each passenger's pickup
 * before her own drop-off. Swapping them asks to travel against the commute.
 */
it('refuses two points that are the wrong way round for the commute', function () {
    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        // The far end as the pickup, the near end as the drop-off: the reverse of this journey.
        'pickup' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
        'dropoff' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road, Rehab exit'],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_POINTS_OUT_OF_ORDER');
});

/**
 * 🔴 The pair is measured together against the driver's own limit. Measuring them one at a time
 * would let each half pass a check the two together break — the same hole `PickupDetour` already
 * documents for separately approved pickups.
 */
it('refuses a pair whose combined detour exceeds the driver’s stated limit', function () {
    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        // Far off the corridor in both directions.
        'pickup' => ['lat' => 30.6000, 'lng' => 31.4000, 'label' => 'Far north'],
        'dropoff' => ['lat' => 29.5000, 'lng' => 31.0000, 'label' => 'Far south'],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PICKUP_DETOUR_TOO_LONG');
});

/**
 * 🔴 v5, twice over: "absence of suggestions must never block a passenger request", and "Never
 * reject publishing merely because the driver provided zero suggested pickup/drop-off points."
 *
 * The commute in `beforeEach` advertises none — `saveRoute` sends only an origin and a destination
 * — and it both published and is requestable with two custom pins.
 */
it('lets her request a seat on a commute that advertises no suggested points at all', function () {
    expect(CommuteLocation::query()
        ->where('commute_offer_id', $this->commuteId)
        ->whereIn('type', [CommuteLocationType::Pickup->value, CommuteLocationType::Dropoff->value])
        ->count())->toBe(0);

    requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201);
});

/**
 * 🔴 v5 §5: a booking carries "immutable agreed pickup/drop-off snapshots".
 *
 * Frozen for the same reason the three money figures are: the request is a live row that a
 * counteroffer or a later edit can change, and a booking has to keep saying where this person was
 * promised to be collected and set down on THIS day.
 */
it('snapshots the agreed points onto the booking', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $bookingId = approveSeat($this->driverToken, $requestId);
    $booking = Booking::findOrFail($bookingId);

    expect((float) $booking->pickup_lat)->toBe(30.0624)
        ->and($booking->pickup_label)->toBe('Ring Road, Rehab exit')
        ->and((float) $booking->dropoff_lat)->toBe(30.0684)
        ->and($booking->dropoff_label)->toBe('Mehwar, Sheikh Zayed')
        ->and($booking->dropoff_point?->lng)->toBe(31.1015);

    // 🔴 And it does not follow the request afterwards. Moving the request's point leaves the
    // booking saying what was actually agreed for this morning.
    SeatRequest::query()->whereKey($requestId)->update(['requested_dropoff_label' => 'Somewhere else']);

    expect(Booking::findOrFail($bookingId)->dropoff_label)->toBe('Mehwar, Sheikh Zayed');
});

/**
 * 🔒 The drop-off is as private as the pickup, and is withheld on the same terms. A drop-off is
 * usually a workplace; a precise one on a booking that is not confirmed yet tells a stranger where
 * to wait for her at the other end of the day.
 */
it('withholds the exact drop-off until the booking is confirmed', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $bookingId = approveSeat($this->driverToken, $requestId);

    $confirmed = test()->withToken($this->paxToken)
        ->getJson("/api/v1/bookings/{$bookingId}")->assertOk()->json('data.dropoffPoint');

    expect($confirmed['isExact'])->toBeTrue()
        ->and($confirmed['lat'])->toBe(30.0684)
        ->and($confirmed['label'])->toBe('Mehwar, Sheikh Zayed');

    // Cancelled is no longer a relationship that has earned the exact place or its name.
    test()->withToken($this->paxToken)
        ->patchJson("/api/v1/bookings/{$bookingId}/cancel")->assertOk();

    $after = test()->withToken($this->paxToken)
        ->getJson("/api/v1/bookings/{$bookingId}")->assertOk()->json('data.dropoffPoint');

    expect($after['isExact'])->toBeFalse()
        ->and($after['label'])->toBeNull()
        ->and($after['lat'])->not->toBe(30.0684);
});
