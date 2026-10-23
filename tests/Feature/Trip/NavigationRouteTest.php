<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Trip\Models\NavigationStop;

/**
 * The driver's map: the stops she actually drives, and the Google Maps button (Feature 03 v5 §3, §4).
 *
 * This file follows v5's own required acceptance tests 2, 5 to 8 and 11 to 12, which are written in
 * terms of a point count: *"Start + End = 2 points. After accepting passenger A (A Pickup + A
 * Drop-off) = 4 points. After accepting passenger B = 6 points ... With N confirmed passengers,
 * there are 2 + 2N logical points"*.
 *
 * 🔴 Nothing is deduplicated, and the counts above are why. Two riders collected at the same corner
 * are still two people to wait for and two attendance decisions — merging them would make the
 * second invisible, and would break the 4/6 contract v5 states.
 */
beforeEach(function () {
    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['seatsTotal' => 3]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
});

function navigationRoute(string $driverToken, string $tripId): array
{
    return test()->withToken($driverToken)
        ->getJson("/api/v1/driver/commute-occurrences/{$tripId}/navigation-route")
        ->assertOk()
        ->json('data');
}

/**
 * Somebody confirmed on the day, with her own two points.
 *
 * @param  array{pickup: array{lat: float, lng: float, label: string}, dropoff: array{lat: float, lng: float, label: string}}  $points
 */
function riderOn(string $driverToken, string $commuteId, string $tripId, string $phone, string $device, array $points): string
{
    $token = verifiedPassenger($phone, device: $device);

    $requestId = requestSeat($token, $commuteId, [
        'scheduledTripId' => $tripId,
        'pickup' => $points['pickup'],
        'dropoff' => $points['dropoff'],
    ])->assertStatus(201)->json('data.id');

    approveSeat($driverToken, $requestId);

    return $requestId;
}

/**
 * 🔴 v5 acceptance test 6, first half: an empty run is her two endpoints and nothing else.
 */
it('is the driver’s start and end alone when nobody is confirmed', function () {
    $route = navigationRoute($this->driverToken, $this->tripId);

    expect($route['pointCount'])->toBe(2)
        ->and($route['status'])->toBe('CONFIRMED')
        ->and(array_column($route['stops'], 'kind'))->toBe(['DRIVER_START', 'DRIVER_END'])
        // The road between them, not two isolated pins — v5 §1 is explicit that a map with two
        // pins "is not sufficient".
        ->and($route['polyline'])->not->toBeEmpty()
        ->and($route['distanceMeters'])->toBeGreaterThan(0);
});

/**
 * 🔴 v5 acceptance test 6: accepting A gives Start + A pickup + A drop-off + End = 4.
 */
it('grows to four points when one passenger is confirmed', function () {
    riderOn($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1', [
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar'],
    ]);

    $route = navigationRoute($this->driverToken, $this->tripId);

    expect($route['pointCount'])->toBe(4)
        ->and(array_column($route['stops'], 'kind'))->toBe([
            'DRIVER_START', 'PASSENGER_PICKUP', 'PASSENGER_DROPOFF', 'DRIVER_END',
        ])
        // Named, so she knows who she is waiting for rather than only where.
        ->and($route['stops'][1]['passengerFirstName'])->not->toBeEmpty()
        ->and($route['stops'][1]['label'])->toBe('Ring Road')
        // 🔴 The external button works at this size.
        ->and($route['googleMapsDirectionsUrl'])->toContain('origin=')
        ->and($route['exceedsWaypointLimit'])->toBeFalse();
});

/**
 * 🔴 v5 acceptance test 7: two passengers make six points, each pickup before her OWN drop-off —
 * and the order may interleave.
 *
 * The coordinates here make it interleave: B is collected between A's pickup and A's drop-off, so
 * the naive `Start → A pickup → A drop-off → B pickup → B drop-off → End` would send the driver to
 * Sheikh Zayed and back to Rehab for a neighbour. v5 warns against assuming that order for exactly
 * this reason.
 */
it('interleaves two passengers and still keeps each pickup before her own drop-off', function () {
    riderOn($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1', [
        // Collected early, set down late: she rides almost the whole way.
        'pickup' => ['lat' => 30.0600, 'lng' => 31.4500, 'label' => 'A pickup'],
        'dropoff' => ['lat' => 30.0710, 'lng' => 31.0000, 'label' => 'A dropoff'],
    ]);

    riderOn($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2', [
        // Collected just after A, set down in the middle.
        'pickup' => ['lat' => 30.0620, 'lng' => 31.3800, 'label' => 'B pickup'],
        'dropoff' => ['lat' => 30.0660, 'lng' => 31.2000, 'label' => 'B dropoff'],
    ]);

    $route = navigationRoute($this->driverToken, $this->tripId);

    expect($route['pointCount'])->toBe(6);

    $labels = array_column($route['stops'], 'label');

    // 🔴 It interleaved: B is picked up and set down INSIDE A's journey, which is the shape v5
    // says never to assume away.
    expect($labels)->toBe([
        'Rehab Gate 2', 'A pickup', 'B pickup', 'B dropoff', 'A dropoff', 'Smart Village B6',
    ]);

    // And the invariant holds for both, which is the rule that matters more than the order.
    foreach ($route['stops'] as $stop) {
        if ($stop['kind'] !== 'PASSENGER_DROPOFF') {
            continue;
        }

        $pickupAt = null;

        foreach ($route['stops'] as $candidate) {
            if ($candidate['kind'] === 'PASSENGER_PICKUP' && $candidate['bookingId'] === $stop['bookingId']) {
                $pickupAt = $candidate['sequence'];
            }
        }

        expect($pickupAt)->not->toBeNull()
            ->and($pickupAt)->toBeLessThan($stop['sequence']);
    }
});

/**
 * 🔴 The repair, head on: a passenger travelling against the commute's flow would otherwise have
 * her drop-off ordered BEFORE her pickup, and a route that sets a woman down before collecting her
 * is not a route.
 *
 * Her request is accepted because the detour is small; it is only the ORDER that needs fixing.
 */
it('never sets a passenger down before it has collected her', function () {
    riderOn($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1', [
        // A short hop backwards along the corridor: pickup further along than drop-off.
        'pickup' => ['lat' => 30.0650, 'lng' => 31.2500, 'label' => 'Later on the road'],
        'dropoff' => ['lat' => 30.0660, 'lng' => 31.2200, 'label' => 'Earlier on the road'],
    ]);

    $stops = navigationRoute($this->driverToken, $this->tripId)['stops'];

    $pickupAt = null;
    $dropoffAt = null;

    foreach ($stops as $stop) {
        if ($stop['kind'] === 'PASSENGER_PICKUP') {
            $pickupAt = $stop['sequence'];
        }

        if ($stop['kind'] === 'PASSENGER_DROPOFF') {
            $dropoffAt = $stop['sequence'];
        }
    }

    expect($pickupAt)->toBeLessThan($dropoffAt)
        // And it sits immediately after, not shuffled to the end of the run.
        ->and($dropoffAt)->toBe($pickupAt + 1);
});

/**
 * 🔴 v5 acceptance test 11: "Canceled booking removes that passenger's future route stops,
 * recalculates itinerary and notifies driver; audit snapshots remain."
 */
it('removes a cancelled passenger’s stops and keeps the booking’s own snapshot', function () {
    $requestId = riderOn($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1', [
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar'],
    ]);

    expect(navigationRoute($this->driverToken, $this->tripId)['pointCount'])->toBe(4);

    $bookingId = Booking::query()->where('seat_request_id', $requestId)->sole()->getKey();

    test()->withToken($this->driverToken)
        ->patchJson("/api/v1/bookings/{$bookingId}/cancel")->assertOk();

    expect(navigationRoute($this->driverToken, $this->tripId)['pointCount'])->toBe(2)
        // 🔴 The audit snapshot survives on the booking, which is the whole reason the agreed
        // points live there rather than only in the itinerary.
        ->and(Booking::findOrFail($bookingId)->pickup_label)->toBe('Ring Road');
});

/**
 * 🔴 v5 §5 asks for the preview to be "clearly marked `PREVIEW_NOT_CONFIRMED`", and v5 acceptance
 * test 5: the preview can show 4 points while the official route "remains 2 until acceptance".
 */
it('previews a pending request’s impact without touching the official route', function () {
    $paxToken = verifiedPassenger('01112223344');

    $requestId = requestSeat($paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar'],
    ])->assertStatus(201)->json('data.id');

    $preview = test()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/seat-requests/{$requestId}/route-preview")
        ->assertOk()->json('data');

    expect($preview['status'])->toBe('PREVIEW_NOT_CONFIRMED')
        ->and($preview['pointCount'])->toBe(4);

    // The two proposed stops are marked as the proposal, not as part of the run.
    $proposed = array_values(array_filter($preview['stops'], fn (array $s): bool => $s['isProposed']));

    expect($proposed)->toHaveCount(2)
        // No ids: these rows do not exist, and a client must not be able to act on one.
        ->and($proposed[0]['id'])->toBeNull();

    // 🔴 And the official itinerary is untouched: still two points, and nothing was written.
    expect(navigationRoute($this->driverToken, $this->tripId)['pointCount'])->toBe(2)
        ->and(NavigationStop::query()->whereNotNull('booking_id')->count())->toBe(0);
});

/**
 * 🔒 v5 §4: "the driver can see a passenger's pending proposed points only inside their own request
 * review". Her itinerary is a list of other women's front doors and workplaces.
 */
it('hides the route from the passengers riding on it', function () {
    $paxToken = verifiedPassenger('01112223344');

    riderOn($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2', [
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar'],
    ]);

    test()->flushHeaders()->withToken($paxToken)
        ->getJson("/api/v1/driver/commute-occurrences/{$this->tripId}/navigation-route")
        // 404 rather than 403: a 403 would confirm the day exists.
        ->assertStatus(404);
});

/**
 * 🔴 The driver's progress survives a rebuild. `reached_at` is the only column here that cannot be
 * recomputed, and a passenger confirmed mid-run must not wipe the record of the kerbs she has
 * already pulled away from.
 */
it('keeps a stop marked as reached when the itinerary is rebuilt around it', function () {
    riderOn($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1', [
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'A pickup'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'A dropoff'],
    ]);

    navigationRoute($this->driverToken, $this->tripId);

    $pickup = NavigationStop::query()->where('kind', 'passenger_pickup')->sole();
    $pickup->forceFill(['reached_at' => now()])->save();

    // A second passenger reorders the run and forces a rebuild.
    riderOn($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2', [
        'pickup' => ['lat' => 30.0620, 'lng' => 31.3800, 'label' => 'B pickup'],
        'dropoff' => ['lat' => 30.0660, 'lng' => 31.2000, 'label' => 'B dropoff'],
    ]);

    $route = navigationRoute($this->driverToken, $this->tripId);

    expect($route['pointCount'])->toBe(6);

    $stillReached = array_values(array_filter(
        $route['stops'],
        fn (array $s): bool => $s['label'] === 'A pickup',
    ));

    expect($stillReached[0]['reachedAt'])->not->toBeNull();
});

/**
 * 🔴 v5 acceptance test 12 and §4: "Google Maps unavailable or waypoint limit exceeded → no crash
 * or truncated hidden itinerary; full RAFEEQ route still available with explicit fallback."
 *
 * The limit is lowered for the test rather than seating five passengers in a three-seat car: the
 * behaviour under test is what happens at the boundary, not how many seats a Corolla has.
 */
it('refuses to pretend one map link can carry an itinerary past the waypoint limit', function () {
    config(['rafeeq.maps.max_waypoints' => 1]);

    riderOn($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1', [
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'A pickup'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'A dropoff'],
    ]);

    $route = navigationRoute($this->driverToken, $this->tripId);

    // Four points means two waypoints, which is past a limit of one.
    expect($route['pointCount'])->toBe(4)
        ->and($route['exceedsWaypointLimit'])->toBeTrue()
        // 🔴 No link at all, rather than one Maps would silently truncate. A driver opening a
        // route that looks complete and is missing a passenger finds out at a kerb.
        ->and($route['googleMapsDirectionsUrl'])->toBeNull()
        ->and($route['waypointLimit'])->toBe(1)
        // The fallback: consecutive segments, each inside the limit.
        ->and(count($route['segments']))->toBeGreaterThan(1)
        // And the authoritative route is still here in full — that is the part that must never be
        // lost to a third party's URL rules.
        ->and($route['polyline'])->not->toBeEmpty()
        ->and($route['stops'])->toHaveCount(4);
});

/**
 * Each segment ends where the next begins, so following them in order drives the whole route. A
 * gap between two segments would be the stretch between two passengers.
 */
it('overlaps the fallback segments so no leg goes unnavigated', function () {
    config(['rafeeq.maps.max_waypoints' => 1]);

    riderOn($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1', [
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'A pickup'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'A dropoff'],
    ]);

    $segments = navigationRoute($this->driverToken, $this->tripId)['segments'];

    for ($i = 0; $i < count($segments) - 1; $i++) {
        parse_str((string) parse_url($segments[$i], PHP_URL_QUERY), $current);
        parse_str((string) parse_url($segments[$i + 1], PHP_URL_QUERY), $next);

        expect($next['origin'])->toBe($current['destination']);
    }
});

/**
 * The ETAs are measured, not apportioned. Each one is the sum of real routed legs from the
 * departure, which is why a leg carries its own duration beside it — v5: "Do not create fake
 * distance/duration/ETA values."
 */
it('counts each stop’s ETA from the legs it routed', function () {
    riderOn($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1', [
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'A pickup'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'A dropoff'],
    ]);

    $route = navigationRoute($this->driverToken, $this->tripId);
    $trip = ScheduledTrip::findOrFail($this->tripId);

    // She starts where she starts: no leg into the first stop.
    expect($route['stops'][0]['legDurationSeconds'])->toBe(0)
        ->and($route['stops'][0]['etaAt'])->toBe($trip->departure_at->toIso8601String());

    $summed = 0;

    foreach ($route['stops'] as $stop) {
        $summed += $stop['legDurationSeconds'];
    }

    // The whole run is its parts, so a client adding up the legs gets the figure we published.
    expect($summed)->toBe($route['durationSeconds'])
        ->and($route['stops'][3]['etaAt'])
        ->toBe($trip->departure_at->copy()->addSeconds($route['durationSeconds'])->toIso8601String());
});

/**
 * 🔴 v5 acceptance test 2: a driver's suggested points *"appear as suggestions but are **not
 * mandatory stops** in the external navigation itinerary until a passenger selects them and is
 * confirmed"*.
 *
 * The inverse of the test that proves a suggestion CAN be chosen, and the more important half: v5
 * §3 is explicit that "driver-suggested points are NOT automatically extra required stops" and that
 * the 4/6-point counts must hold with none of them in the route. A commute advertising three
 * meeting places that nobody picked would otherwise send the driver on a tour of them.
 */
it('leaves an advertised meeting point out of the route until somebody picks it', function () {
    $commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['seatsTotal' => 3]);

    saveRoute($this->driverToken, $commuteId, [
        'pickups' => [
            ['lat' => 30.0624, 'lng' => 31.3614, 'address' => 'Advertised one'],
            ['lat' => 30.0660, 'lng' => 31.2400, 'address' => 'Advertised two'],
        ],
    ])->assertOk();

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $tripId = ScheduledTrip::query()->where('commute_offer_id', $commuteId)
        ->orderBy('trip_date')->firstOrFail()->id;

    // They exist as suggestions on the commute...
    expect(CommuteLocation::query()
        ->where('commute_offer_id', $commuteId)
        ->where('type', CommuteLocationType::Pickup->value)
        ->count())->toBe(2);

    // ...and the run she actually drives is still just her own two endpoints.
    $route = navigationRoute($this->driverToken, $tripId);

    expect($route['pointCount'])->toBe(2)
        ->and(array_column($route['stops'], 'label'))->toBe(['Rehab Gate 2', 'Smart Village B6']);

    // One passenger picks ONE of them, and only that one becomes a stop.
    $paxToken = verifiedPassenger('01112223344', device: 'pax-1');

    $suggestion = CommuteLocation::query()
        ->where('commute_offer_id', $commuteId)
        ->where('address', 'Advertised one')
        ->sole();

    $requestId = requestSeat($paxToken, $commuteId, [
        'scheduledTripId' => $tripId,
        'pickup' => ['suggestionId' => $suggestion->getKey()],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar'],
    ])->assertStatus(201)->json('data.id');

    approveSeat($this->driverToken, $requestId);

    $after = navigationRoute($this->driverToken, $tripId);

    // Four, not five: "Advertised two" is still only an offer she made, not a place to drive to.
    expect($after['pointCount'])->toBe(4)
        ->and(array_column($after['stops'], 'label'))->not->toContain('Advertised two');
});

/**
 * 🔴 v5 acceptance test 8: *"Driver rejects passenger C → C's proposed points never appear in
 * confirmed itinerary."*
 *
 * Distinct from the pending case, which the preview test covers. A refusal is the moment those
 * coordinates stop being a proposal and become somebody else's business entirely — and the
 * itinerary is the one place they must never leak into, because every confirmed passenger can see
 * it through the driver's screen.
 */
it('never puts a refused passenger’s points in the route', function () {
    $paxToken = verifiedPassenger('01112223344', device: 'pax-1');

    $requestId = requestSeat($paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Refused pickup'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Refused dropoff'],
    ])->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/reject")
        ->assertOk();

    $route = navigationRoute($this->driverToken, $this->tripId);

    expect($route['pointCount'])->toBe(2)
        ->and(array_column($route['stops'], 'label'))
        ->not->toContain('Refused pickup')
        ->not->toContain('Refused dropoff')
        // And nothing was written down for her either.
        ->and(NavigationStop::query()->whereNotNull('booking_id')->count())->toBe(0);
});
