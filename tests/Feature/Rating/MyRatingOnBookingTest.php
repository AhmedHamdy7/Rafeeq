<?php

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Rating\Models\Rating;
use Illuminate\Support\Facades\Storage;

/**
 * HISTORY-4: the caller's own rating on every booking (`myRating`).
 *
 * The History card says "you rated 5 ★" and the past trip's Edit opens with the old stars filled
 * in. Before this the app paged `GET /ratings/mine` per screen to find one row. What must not move
 * is double-blind: the field is the caller's own rating or null, and nothing in it — or around it —
 * says whether the other person has rated.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $this->paxToken = verifiedPassenger('01112223344');

    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));
});

function bookingAs(string $token, string $bookingId): array
{
    return test()->flushHeaders()->withToken($token)
        ->getJson("/api/v1/bookings/{$bookingId}")
        ->assertOk()
        ->json('data');
}

it('is null before she has rated', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    expect(bookingAs($this->paxToken, $this->bookingId))->toHaveKey('myRating')
        ->and(bookingAs($this->paxToken, $this->bookingId)['myRating'])->toBeNull();
});

it('returns what she wrote, editable while the window is open', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $id = rateBooking($this->paxToken, $this->bookingId, [
        'stars' => 5,
        'comment' => 'Very punctual and kind.',
        'tags' => ['on_time'],
    ])->assertStatus(201)->json('data.id');

    $mine = bookingAs($this->paxToken, $this->bookingId)['myRating'];

    expect($mine['id'])->toBe($id)
        ->and($mine['stars'])->toBe(5)
        ->and($mine['comment'])->toBe('Very punctual and kind.')
        ->and($mine['tags'])->toBe(['on_time'])
        ->and($mine['kind'])->toBe('completed_ride')
        ->and($mine['isVisible'])->toBeFalse()
        ->and($mine['editableUntil'])->not->toBeNull();
});

it('carries it on every card of the list too', function () {
    journeyCompleted($this->driverToken, $this->tripId);
    rateBooking($this->paxToken, $this->bookingId, ['stars' => 4])->assertStatus(201);

    $card = test()->flushHeaders()->withToken($this->paxToken)
        ->getJson('/api/v1/my-bookings?scope=past')
        ->assertOk()
        ->json('data.0');

    expect($card['myRating']['stars'])->toBe(4);
});

/**
 * 🔴 The double-blind test this field had to pass. The driver has rated her; she has not rated
 * him. Her booking must look exactly as it would if nobody had rated at all.
 */
it('says nothing when only the other side has rated', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $driversId = rateBooking($this->driverToken, $this->bookingId, [
        'stars' => 2,
        'comment' => 'Kept the car waiting at the gate.',
    ])->assertStatus(201)->json('data.id');

    $booking = bookingAs($this->paxToken, $this->bookingId);
    $flat = json_encode($booking, JSON_THROW_ON_ERROR);

    expect($booking['myRating'])->toBeNull()
        ->and($flat)->not->toContain($driversId)
        ->and($flat)->not->toContain('Kept the car waiting');

    // And the driver's own view shows his rating, which is his to read.
    expect(bookingAs($this->driverToken, $this->bookingId)['myRating']['id'])->toBe($driversId);
});

it('shows no Edit once the edit window has run out', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $id = rateBooking($this->paxToken, $this->bookingId, ['stars' => 5])->assertStatus(201)->json('data.id');

    Rating::query()->whereKey($id)->update(['edit_deadline_at' => now()->subMinute()]);

    expect(bookingAs($this->paxToken, $this->bookingId)['myRating']['editableUntil'])->toBeNull();
});

it('shows no Edit once a completed-ride rating has been revealed', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    rateBooking($this->paxToken, $this->bookingId, ['stars' => 5])->assertStatus(201);
    rateBooking($this->driverToken, $this->bookingId, ['stars' => 4])->assertStatus(201);

    $mine = bookingAs($this->paxToken, $this->bookingId)['myRating'];

    expect($mine['isVisible'])->toBeTrue()
        ->and($mine['editableUntil'])->toBeNull();
});

/**
 * Cancelled-seat feedback is published at once AND may still be fixed — the server lets her PATCH
 * it, so `editableUntil` must say so, or the app hides an Edit that would work.
 */
it('keeps Edit on cancelled-seat feedback while its window is open', function () {
    test()->flushHeaders()->withToken($this->driverToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    rateBooking($this->paxToken, $this->bookingId, ['stars' => 2])->assertStatus(201)
        ->assertJsonPath('data.isVisible', true);

    $mine = bookingAs($this->paxToken, $this->bookingId)['myRating'];

    expect($mine['kind'])->toBe('canceled_booking')
        ->and($mine['editableUntil'])->not->toBeNull();
});
