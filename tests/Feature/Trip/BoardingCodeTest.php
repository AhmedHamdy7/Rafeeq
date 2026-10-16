<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Support\Facades\Storage;

/**
 * The short code the passenger checks against the car before getting in, which the design shows on
 * the active-trip screen directly beneath the driver and the vehicle:
 *
 *     Nour · Kia Sportage · Silver
 *     Plate ﺱ 4821 · PIN 7391
 *
 * 🔴 The most important thing this file asserts is a NEGATIVE: the code decides nothing.
 *
 * Decision D18 gives attendance to the driver's tap, and the Master Plan considered and rejected a
 * code for that job in as many words — "a PIN fails when somebody is running for the car". So
 * check-in must keep working with no code at all, and these tests exist mostly to stop a future
 * change making it mandatory. The failure mode that guards against is a woman standing beside a car
 * she has verified by plate and sight, locked out of her own commute by a flat battery.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
    $this->paxToken = verifiedPassenger('01112223344');

    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $this->bookingId = approveSeat($this->driverToken, $requestId);
});

it('gives the passenger a four-digit code with her booking', function () {
    $code = test()->withToken($this->paxToken)
        ->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()->json('data.boardingCode');

    expect($code)->toMatch('/^\d{4}$/')
        ->and($code)->toBe(Booking::findOrFail($this->bookingId)->boarding_code);
});

/**
 * 🔒 Both sides see the same number, and that is what makes it usable at all: a code only the
 * passenger can see is a code nobody can confirm.
 */
it('shows the driver the same code, so the number can actually be checked', function () {
    $passengerSees = test()->withToken($this->paxToken)
        ->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()->json('data.boardingCode');

    $driverSees = test()->withToken($this->driverToken)
        ->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()->json('data.boardingCode');

    expect($driverSees)->toBe($passengerSees);
});

it('puts it on the driver`s attendance list for the run', function () {
    underway($this->driverToken, $this->tripId);

    $rows = test()->withToken($this->driverToken)
        ->getJson("/api/v1/trips/{$this->tripId}/attendance")
        ->assertOk()->json('data');

    expect($rows)->not->toBeEmpty()
        ->and($rows[0]['boardingCode'])->toBe(Booking::findOrFail($this->bookingId)->boarding_code);
});

/**
 * 🔴 The negative that matters. Check-in takes no code and must never start requiring one.
 */
it('checks a passenger in with no code at all', function () {
    underway($this->driverToken, $this->tripId);

    checkIn($this->driverToken, $this->tripId, [
        'bookingId' => $this->bookingId,
        'position' => position(),
    ])->assertOk();

    expect(Booking::findOrFail($this->bookingId)->boarding_code)->not->toBeNull();
});

/**
 * Each day gets its own number, with no extra machinery: a booking is one journey, and recurring
 * membership generates a booking per trip.
 */
/**
 * 🔒 It must not leak sideways into the payloads a WHOLE GROUP reads.
 *
 * The booking payload is scoped to the two people on it, but the trip and the group member list are
 * not — a commute carries up to three passengers, and the member list is read by all of them. A
 * code visible there would let a fellow passenger answer the question a stranger at the kerb is
 * being asked.
 *
 * Asserted against the raw body rather than a key, because the point is that the digits appear
 * nowhere, at any nesting depth.
 */
it('appears in no payload that other passengers can read', function () {
    underway($this->driverToken, $this->tripId);

    $code = Booking::findOrFail($this->bookingId)->boarding_code;

    $trip = test()->withToken($this->paxToken)
        ->getJson("/api/v1/trips/{$this->tripId}")->assertOk();

    $groups = test()->withToken($this->paxToken)
        ->getJson('/api/v1/groups')->assertOk();

    expect($trip->getContent())->not->toContain($code)
        ->and($groups->getContent())->not->toContain($code);
});

/**
 * 🔒 Nobody else may read it. A stranger holding a booking id must not be able to fetch the number
 * a passenger is about to read out at a kerb.
 */
it('is not readable by somebody outside the booking', function () {
    $stranger = verifiedPassenger('01099887766', device: 'pax-stranger');

    test()->withToken($stranger)
        ->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertStatus(404);
});
