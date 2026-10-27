<?php

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;
use Illuminate\Support\Facades\Storage;

/**
 * The Call button beside Message (Feature 03 v5 §247 and §426).
 *
 * > *"Driver call icon appears **only for next passenger to pick up and after trip starts**;
 * > passenger call icon **from booking acceptance**. Call opens device dialer."*
 *
 * 🔒 The asymmetry is the whole feature, and these tests are mostly about the half that refuses. A
 * passenger may reach her driver from the moment her seat is confirmed, because she is about to get
 * into that person's car. A driver gets exactly one number at a time, only while driving, and only
 * for the woman she is on her way to — a driver holding five bookings does not get five phone
 * numbers for the week.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');

    $this->bookingId = approveSeat(
        $this->driverToken,
        requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
            ->assertStatus(201)->json('data.id'),
    );
});

function askForNumber(string $token, string $bookingId)
{
    return test()->flushHeaders()->withToken($token)
        ->getJson("/api/v1/bookings/{$bookingId}/call");
}

/*
|--------------------------------------------------------------------------
| The passenger's side
|--------------------------------------------------------------------------
*/

it('gives the passenger her driver’s number as soon as the seat is confirmed', function () {
    askForNumber($this->paxToken, $this->bookingId)
        ->assertOk()
        // The stored form, dialable, because v5 says the call opens the device dialler.
        ->assertJsonPath('data.phone', '+201012345678')
        // Enough to put a name on the dialler screen, and nothing more.
        ->assertJsonPath('data.name', fn ($value) => is_string($value) && $value !== '')
        ->assertJsonStructure(['data' => ['phone', 'name']]);

    // 🔒 Nothing else about the person rides along.
    $payload = askForNumber($this->paxToken, $this->bookingId)->json('data');

    expect(array_keys($payload))->toBe(['phone', 'name']);
});

/**
 * The run has not started and she can still call — that is the point of her gate being acceptance
 * rather than departure. Most of what she needs a phone for happens before the car arrives.
 */
it('does not make her wait for the run to start', function () {
    runLeavingIn($this->tripId, 120);

    askForNumber($this->paxToken, $this->bookingId)->assertOk();
});

it('stops once her seat is no longer live', function () {
    test()->withToken($this->paxToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel", ['reason' => 'Plans changed'])
        ->assertOk();

    askForNumber($this->paxToken, $this->bookingId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CALL_NOT_AVAILABLE')
        ->assertJsonPath('error.fields.reason.0', 'booking_not_live');
});

/*
|--------------------------------------------------------------------------
| The driver's side
|--------------------------------------------------------------------------
*/

/**
 * 🔒 Before the car moves, she gets nothing — however confirmed the booking is.
 */
it('refuses the driver before she has set off', function () {
    askForNumber($this->driverToken, $this->bookingId)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.reason.0', 'trip_not_started');
});

it('gives her the number of the passenger she is on her way to', function () {
    underway($this->driverToken, $this->tripId);

    // Reading the route is what builds the itinerary the gate is read from.
    test()->flushHeaders()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/commute-occurrences/{$this->tripId}/navigation-route")
        ->assertOk();

    askForNumber($this->driverToken, $this->bookingId)
        ->assertOk()
        ->assertJsonPath('data.phone', '+201223339999');
});

/**
 * 🔴 One number at a time. A driver with two confirmed passengers gets the one she is approaching,
 * and is refused the other until she has collected the first.
 */
it('gives her one number at a time, in the order she reaches them', function () {
    $secondToken = verifiedPassenger('01222220007', device: 'pax-7');

    $secondBooking = approveSeat(
        $this->driverToken,
        requestSeat($secondToken, $this->commuteId, [
            'scheduledTripId' => $this->tripId,
            // Further along the route, so her pickup is ordered after the first.
            'pickup' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
            'dropoff' => ['lat' => 30.0710, 'lng' => 30.9750, 'label' => 'Smart Village gate'],
        ])->assertStatus(201)->json('data.id'),
    );

    underway($this->driverToken, $this->tripId);

    test()->flushHeaders()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/commute-occurrences/{$this->tripId}/navigation-route")
        ->assertOk();

    $first = NavigationStop::query()
        ->where('scheduled_trip_id', $this->tripId)
        ->where('kind', NavigationStopKind::PassengerPickup->value)
        ->orderBy('sequence')
        ->first();

    // Whichever of the two comes first on the road is the one she can reach.
    $reachable = $first->booking_id;
    $other = $reachable === $this->bookingId ? $secondBooking : $this->bookingId;

    askForNumber($this->driverToken, $reachable)->assertOk();

    askForNumber($this->driverToken, $other)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.reason.0', 'not_next_pickup');

    // Once the first is collected, the next one becomes reachable.
    $first->forceFill(['reached_at' => now()])->save();

    askForNumber($this->driverToken, $other)->assertOk();
    askForNumber($this->driverToken, $reachable)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.reason.0', 'not_next_pickup');
});

/**
 * ⚠️ No itinerary means no "next", and the answer is no. The safe direction: a driver who has not
 * opened her route has not started approaching anybody.
 */
it('refuses her when no itinerary has been built', function () {
    underway($this->driverToken, $this->tripId);

    expect(NavigationStop::query()->count())->toBe(0);

    askForNumber($this->driverToken, $this->bookingId)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.reason.0', 'not_next_pickup');
});

/*
|--------------------------------------------------------------------------
| Guards and the audit line
|--------------------------------------------------------------------------
*/

/**
 * 🔒 Nobody who is not one of the two gets anything, and a miss is a 404 — a 403 would confirm the
 * booking exists.
 */
it('refuses a stranger', function () {
    $stranger = verifiedPassenger('01299887766', device: 'pax-9');

    askForNumber($stranger, $this->bookingId)->assertStatus(404);

    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223338888', devicePublicId: 'driver-2', seed: 2);

    askForNumber($otherDriver, $this->bookingId)->assertStatus(404);
});

/**
 * 🔒 A number handed over rather than masked is a thing that should be answerable afterwards: who
 * got whose, on which booking, and when.
 */
it('writes a security-log line for every disclosure', function () {
    askForNumber($this->paxToken, $this->bookingId)->assertOk();

    $event = SecurityEvent::query()->where('event_type', 'phone_number_disclosed')->sole();

    expect($event->metadata['booking_id'])->toBe($this->bookingId)
        // The log says who it was about without storing the number a second time.
        ->and($event->metadata)->not->toHaveKey('phone')
        ->and(json_encode($event->metadata))->not->toContain('+2010');
});

/**
 * 🔒 And a refused request discloses nothing and logs no disclosure — the line means a number
 * actually went out.
 */
it('logs nothing when the number is refused', function () {
    askForNumber($this->driverToken, $this->bookingId)->assertStatus(409);

    expect(SecurityEvent::query()->where('event_type', 'phone_number_disclosed')->count())->toBe(0);
});

/**
 * 🔒 The number still appears nowhere else. This endpoint is the only way to it, which is what
 * keeps it out of payloads that get cached and logged.
 */
it('keeps the number off the booking payload', function () {
    $booking = test()->withToken($this->paxToken)
        ->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()
        ->json();

    expect(json_encode($booking))->not->toContain('+201012345678')
        ->and(json_encode($booking))->not->toContain('phone');
});
