<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;

/**
 * HISTORY-1: the route, the driver and the pickup on every booking of the passenger's own lists.
 *
 * The Trips tab's History list shows "Thu 15 Oct · Rehab Gate 2 → Smart Village B6 · Nour". Before
 * this a booking carried a date, a price and a status, so every card on a recurring commute read
 * the same and she could not tell her journeys apart. `GET /home` already had these facts on
 * `nextJourney`; these tests hold the same facts to every booking in `GET /my-bookings` and
 * `GET /bookings/{booking}`, and hold the privacy lines that come with them.
 */
beforeEach(function () {
    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $this->paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    $this->bookingId = approveSeat($this->driverToken, $requestId);
});

it('names the route and the driver on every booking in the list', function () {
    $booking = test()->flushHeaders()->withToken($this->paxToken)
        ->getJson('/api/v1/my-bookings')
        ->assertOk()
        ->json('data.0');

    $driver = User::query()->where('phone_e164', '+201012345678')->sole();

    expect($booking['commuteId'])->toBe($this->commuteId)
        ->and($booking['originLabel'])->toBe('Rehab Gate 2')
        ->and($booking['destinationLabel'])->toBe('Smart Village B6')
        ->and($booking['driver']['publicFirstName'])->toBe($driver->public_first_name)
        ->and($booking['driver'])->toHaveKeys(['trustLevel', 'rating', 'completedTrips'])
        // The pickup and drop-off she agreed, exact on a confirmed seat.
        ->and($booking['meetingPoint']['isExact'])->toBeTrue()
        ->and($booking['meetingPoint']['lat'])->toBe(30.0624)
        ->and($booking['meetingPoint']['label'])->toBe('Ring Road, Rehab exit')
        ->and($booking['dropoffPoint']['label'])->toBe('Mehwar, Sheikh Zayed');
});

it('gives the past trip screen the same fields in one call', function () {
    $booking = test()->flushHeaders()->withToken($this->paxToken)
        ->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()
        ->json('data');

    expect($booking['commuteId'])->toBe($this->commuteId)
        ->and($booking['originLabel'])->toBe('Rehab Gate 2')
        ->and($booking['destinationLabel'])->toBe('Smart Village B6')
        ->and($booking['driver']['publicFirstName'])->not->toBeNull();
});

/**
 * 🔒 The driver is a `person` (§5.1) and nothing more. A first name, badges and numbers — never a
 * full name, a phone or an id that resolves to an account.
 */
it('tells her the driver’s first name and nothing that identifies him', function () {
    $driver = User::query()->where('phone_e164', '+201012345678')->sole();

    $card = test()->flushHeaders()->withToken($this->paxToken)
        ->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()
        ->json('data.driver');

    $flat = json_encode($card, JSON_THROW_ON_ERROR);

    expect($card)->not->toHaveKeys(['id', 'userId', 'phone', 'phoneE164', 'fullName', 'name', 'email', 'gender'])
        ->and($flat)->not->toContain($driver->id)
        ->and($flat)->not->toContain('1012345678');

    if ($driver->full_name !== null && $driver->full_name !== $driver->public_first_name) {
        expect($flat)->not->toContain($driver->full_name);
    }
});

/**
 * 🔒 A seat that was never confirmed, or that she cancelled, keeps only the fuzzed point — a
 * pickup is often somebody's front door, and the History list must not be the way round that.
 */
it('keeps the pickup fuzzed on a booking that is pending or that she cancelled', function (string $status) {
    Booking::query()->whereKey($this->bookingId)->update(['status' => $status]);

    $booking = test()->flushHeaders()->withToken($this->paxToken)
        ->getJson('/api/v1/my-bookings')
        ->assertOk()
        ->json('data.0');

    expect($booking['meetingPoint']['isExact'])->toBeFalse()
        ->and($booking['meetingPoint']['lat'])->toBe(30.062)
        ->and($booking['meetingPoint']['label'])->toBeNull()
        ->and($booking['dropoffPoint']['isExact'])->toBeFalse()
        ->and($booking['dropoffPoint']['label'])->toBeNull()
        // The route and the driver's first name stay: they are what the card is about, and
        // neither is a place somebody stands.
        ->and($booking['originLabel'])->toBe('Rehab Gate 2')
        ->and($booking['driver']['publicFirstName'])->not->toBeNull();
})->with(['pending', 'cancelled_by_passenger']);
