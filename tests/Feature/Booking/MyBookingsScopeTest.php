<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\ValueObjects\DaysMask;

/**
 * HISTORY-2: `GET /my-bookings?scope=past|upcoming`.
 *
 * Filtered on the phone, a page of mixed bookings could hold two finished ones, so History read up
 * to five pages to fill a screen and `meta.hasMore` described a list it was not showing. These pin
 * that the server filters, orders by the journey, and pages the filtered list.
 */
beforeEach(function () {
    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->paxToken = verifiedPassenger('01112223344');

    // A recurring member: one approval writes a booking per travelling day, all CONFIRMED.
    $requestId = requestSeat($this->paxToken, $this->commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(201);

    // Soonest journey first, by the trip, not by the row.
    $this->bookings = Booking::query()
        ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
        ->orderBy('scheduled_trips.departure_at')
        ->pluck('bookings.id')
        ->all();

    expect(count($this->bookings))->toBeGreaterThan(4);

    // Three finished: two the morning after another, and one cancelled in between.
    Booking::query()->whereKey($this->bookings[0])->update(['status' => 'completed']);
    Booking::query()->whereKey($this->bookings[1])->update(['status' => 'cancelled_by_driver']);
    Booking::query()->whereKey($this->bookings[2])->update(['status' => 'ended_early']);
});

function myBookings(string $token, string $query = '')
{
    return test()->flushHeaders()->withToken($token)->getJson('/api/v1/my-bookings'.$query);
}

it('returns only finished bookings for past, newest journey first', function () {
    $ids = myBookings($this->paxToken, '?scope=past')->assertOk()->json('data.*.id');

    expect($ids)->toBe([$this->bookings[2], $this->bookings[1], $this->bookings[0]]);
});

it('pages the filtered list, so hasMore describes History', function () {
    $first = myBookings($this->paxToken, '?scope=past&perPage=2')->assertOk();

    $first->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.hasMore', true);

    myBookings($this->paxToken, '?scope=past&perPage=2&page=2')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->bookings[0])
        ->assertJsonPath('meta.hasMore', false);
});

it('returns only open seats for upcoming, soonest journey first', function () {
    $response = myBookings($this->paxToken, '?scope=upcoming&perPage=100')->assertOk();

    expect($response->json('data.*.id'))->toBe(array_slice($this->bookings, 3))
        ->and(collect($response->json('data.*.status'))->unique()->values()->all())->toBe(['CONFIRMED']);
});

/**
 * A seat still CONFIRMED on a morning that has gone by is in neither list: the day's sweep settles
 * it, and calling it "upcoming" the day after would be the one answer that is certainly wrong.
 */
it('leaves a confirmed seat on a morning that has passed out of upcoming', function () {
    $stale = $this->bookings[3];

    runLeavingIn(Booking::query()->findOrFail($stale)->scheduled_trip_id, -180, dayOffset: -10);

    $ids = myBookings($this->paxToken, '?scope=upcoming&perPage=100')->assertOk()->json('data.*.id');

    expect($ids)->not->toContain($stale);
});

it('behaves exactly as before without a scope', function () {
    myBookings($this->paxToken, '?perPage=100')->assertOk()
        ->assertJsonCount(count($this->bookings), 'data');
});

it('refuses a scope it does not know', function () {
    myBookings($this->paxToken, '?scope=everything')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('never returns somebody else’s bookings in either scope', function () {
    $stranger = verifiedPassenger('01223339999', device: 'pax-2');

    myBookings($stranger, '?scope=past')->assertOk()->assertJsonCount(0, 'data');
    myBookings($stranger, '?scope=upcoming')->assertOk()->assertJsonCount(0, 'data');
});
