<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\ScheduleLock;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\Exceptions\DomainException;

/**
 * The hours before a departure when a day stops changing (Feature 03 v5).
 *
 * > *"**16 hours before planned departure**, route, departure and pickup schedule become locked.
 * > New bookings after lock only when compatible with frozen commitments."*
 *
 * 🔴 **Two thirds of this was already true, more strictly than v5 asks.** A published commute's
 * route and schedule cannot be edited at all here — `CommuteState::assertEditable()` allows it only
 * on a DRAFT — so they are frozen from the moment it goes up, not sixteen hours before each day.
 * There is nothing to test about a window that does not exist for them, and the first test below
 * pins the stricter rule so nobody "implements the lock" by relaxing it to v5's.
 *
 * What the lock actually governs is the remaining two cases, which are the rest of this file.
 */
beforeEach(function () {
    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, [
        'seatsTotal' => 3,
        'allowsCustomPickup' => true,
    ], ['promisedArrivalTime' => '08:00:00']);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
});

/**
 * Brings a day to a chosen number of hours before its departure, by moving the day rather than the
 * clock: travelling the clock would expire the access tokens these tests hold.
 */
function departingInHours(string $tripId, float $hours): ScheduledTrip
{
    $trip = ScheduledTrip::findOrFail($tripId);

    $departure = now()->addMinutes((int) round($hours * 60));

    $trip->forceFill([
        'departure_at' => $departure,
        'trip_date' => $departure->toDateString(),
        'promised_arrival_at' => $departure->copy()->addMinutes(55),
        // Otherwise the ordinary booking deadline refuses first and this would pass for the wrong
        // reason.
        'booking_deadline_at' => $departure->copy()->addMinute(),
    ])->save();

    return $trip->refresh();
}

/**
 * Somebody seated on the day, with a custom meeting point proposed and waiting for the driver.
 *
 * The proposal happens while her seat request is still PENDING, because that is the order the
 * pickup-point flow requires: a point is proposed as part of asking, not after being seated.
 *
 * @return string the pickup request's id
 */
function proposedPickupOn(string $driverToken, string $commuteId, string $tripId, string $phone): string
{
    $paxToken = verifiedPassenger($phone);

    $requestId = requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($paxToken)
        ->postJson("/api/v1/seat-requests/{$requestId}/pickup-request", onTheWay())
        ->assertStatus(201);

    approveSeat($driverToken, $requestId);

    return PickupPointRequest::sole()->id;
}

/**
 * 🔴 The stricter rule, pinned. v5 would allow a route edit up to sixteen hours before; this
 * codebase allows none after publishing, because people book specific days on the strength of it.
 */
it('never lets a published commute’s route or departure change at all', function () {
    saveRoute($this->driverToken, $this->commuteId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_NOT_EDITABLE');

    saveSchedule($this->driverToken, $this->commuteId, ['departureTime' => '06:00:00'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_NOT_EDITABLE');
});

it('knows when a day has crossed into its lock window', function () {
    expect(ScheduleLock::hours())->toBe((int) config('rafeeq.commute.schedule_lock_hours'))
        ->and(ScheduleLock::isLocked(departingInHours($this->tripId, 20)))->toBeFalse()
        ->and(ScheduleLock::isLocked(departingInHours($this->tripId, 10)))->toBeTrue();
});

/**
 * 🔴 The pickup schedule is the one edit that still reaches into a published commute's future days,
 * which makes it the one place the lock has teeth. Tomorrow morning's kerb stops moving sixteen
 * hours before she stands on it.
 */
it('refuses to move an agreed meeting point inside the window', function () {
    // Proposed while the request is still pending and approved after, which is the order the
    // pickup-point flow requires — a point is proposed as part of asking, not after being seated.
    $pickupId = proposedPickupOn($this->driverToken, $this->commuteId, $this->tripId, '01112223344');

    departingInHours($this->tripId, 10);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_SCHEDULE_LOCKED');

    // 🔴 And nothing half-applied: her booking still says the point she agreed to.
    expect(round((float) Booking::sole()->pickup_lat, 4))->toBe(30.0624);
});

it('still lets her move it while the day is far enough out', function () {
    $pickupId = proposedPickupOn($this->driverToken, $this->commuteId, $this->tripId, '01112223344');

    departingInHours($this->tripId, 30);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/approve")
        ->assertOk();
});

/**
 * 🔒 A locked day with nobody on it has nobody to protect. Refusing an edit there would stop a
 * driver tidying her own empty morning for no one's benefit — the lock exists for the people who
 * have planned around it.
 */
it('does not lock a day nobody has booked', function () {
    departingInHours($this->tripId, 5);

    $trip = ScheduledTrip::findOrFail($this->tripId);

    // The day IS inside the window...
    expect(ScheduleLock::isLocked($trip))->toBeTrue();

    $refused = false;

    try {
        // ...and the guard still lets it through, because nobody is relying on it.
        ScheduleLock::assertNotLocked($trip);
    } catch (DomainException) {
        $refused = true;
    }

    expect($refused)->toBeFalse();
});

/**
 * 🔴 v5's new rule: "New bookings after lock only when compatible with frozen commitments."
 *
 * The rider's two points are far enough off the corridor that adding her would land the run past
 * the arrival the people on board were promised — so the seat is refused rather than the promise
 * quietly broken.
 */
it('refuses a seat inside the window when the rider would make the commute late', function () {
    $paxToken = verifiedPassenger('01112223344');

    $requestId = requestSeat($paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        // On the corridor, so the request itself is accepted: it is the ARRIVAL that fails later.
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar'],
    ])->assertStatus(201)->json('data.id');

    // Inside the window, and with a promise this rider's stops cannot keep: 55 minutes of slack
    // against a run that needs longer once it detours twice.
    $trip = departingInHours($this->tripId, 10);
    $trip->forceFill(['promised_arrival_at' => $trip->departure_at->copy()->addMinutes(20)])->save();

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_BREAKS_FROZEN_PROMISE');

    expect(Booking::query()->count())->toBe(0)
        // Her request survives for a day she can actually be carried on.
        ->and(ScheduledTrip::findOrFail($this->tripId)->seats_taken)->toBe(0);
});

/**
 * 🔴 And the same day, outside the window, is allowed — because before the lock nothing is frozen.
 * Refusing a booking three weeks out because a straight-line estimate said two minutes late would
 * turn an estimate into a promise.
 */
it('allows the same seat while the day is still outside the window', function () {
    $paxToken = verifiedPassenger('01112223344');

    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $trip = departingInHours($this->tripId, 30);
    $trip->forceFill(['promised_arrival_at' => $trip->departure_at->copy()->addMinutes(20)])->save();

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(201);

    expect(Booking::query()->count())->toBe(1);
});

/**
 * No promise, no breach — the principle `CompleteTripAction` already works to. A day that promised
 * no arrival time has nothing to break, and a seat on it is judged by capacity and detour alone.
 */
it('judges a day with no promised arrival on capacity alone', function () {
    $paxToken = verifiedPassenger('01112223344');

    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    departingInHours($this->tripId, 10);
    ScheduledTrip::query()->whereKey($this->tripId)->update(['promised_arrival_at' => null]);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(201);
});
