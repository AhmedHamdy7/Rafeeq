<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Rating\Models\Rating;
use Illuminate\Support\Facades\Storage;

/**
 * The rating rules the Trips history leans on (HISTORY-5, HISTORY-6, HISTORY-7).
 *
 * The app offers Rate only where the server will take it, and Edit only while the server will let
 * it through. These pin the server's side of both, so the two cannot drift apart unnoticed.
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

function editRating(string $token, string $ratingId, array $body)
{
    return test()->flushHeaders()->withToken($token)->patchJson("/api/v1/ratings/{$ratingId}", $body);
}

/*
|--------------------------------------------------------------------------
| HISTORY-5 — what an edit leaves alone, and what it clears
|--------------------------------------------------------------------------
*/

it('clears the comment when it is sent as null', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $id = rateBooking($this->paxToken, $this->bookingId, ['stars' => 5, 'comment' => 'Very punctual.'])
        ->assertStatus(201)->json('data.id');

    editRating($this->paxToken, $id, ['comment' => null])
        ->assertOk()
        ->assertJsonPath('data.comment', null)
        // Stars were not sent, so they stay.
        ->assertJsonPath('data.stars', 5);

    expect(Rating::query()->whereKey($id)->sole()->comment)->toBeNull();
});

/**
 * 🔴 The bug this fixed: the edit shared the submit's request, which turned a left-out comment into
 * null — so changing the stars silently deleted what she had written.
 */
it('keeps the comment and the tags when they are left out', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $id = rateBooking($this->paxToken, $this->bookingId, [
        'stars' => 5,
        'comment' => 'Very punctual.',
        'tags' => ['on_time', 'safe_driving'],
    ])->assertStatus(201)->json('data.id');

    $edited = editRating($this->paxToken, $id, ['stars' => 4])->assertOk()->json('data');

    expect($edited['stars'])->toBe(4)
        ->and($edited['comment'])->toBe('Very punctual.')
        ->and($edited['tags'])->toEqualCanonicalizing(['on_time', 'safe_driving']);
});

it('replaces the comment when text is sent, and clears the tags with an empty list', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $id = rateBooking($this->paxToken, $this->bookingId, [
        'stars' => 5,
        'comment' => 'Very punctual.',
        'tags' => ['on_time'],
    ])->assertStatus(201)->json('data.id');

    $edited = editRating($this->paxToken, $id, ['comment' => 'Punctual, a bit fast on the ring road.', 'tags' => []])
        ->assertOk()->json('data');

    expect($edited['comment'])->toBe('Punctual, a bit fast on the ring road.')
        ->and($edited['tags'])->toBe([])
        ->and($edited['stars'])->toBe(5);
});

it('still refuses a comment longer than a thousand characters on an edit', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $id = rateBooking($this->paxToken, $this->bookingId)->assertStatus(201)->json('data.id');

    editRating($this->paxToken, $id, ['comment' => str_repeat('a', 1001)])->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| HISTORY-6 — stars-only feedback on a seat the driver cancelled (X-2)
|--------------------------------------------------------------------------
*/

it('accepts stars alone on a seat the driver cancelled, published at once', function () {
    test()->flushHeaders()->withToken($this->driverToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    rateBooking($this->paxToken, $this->bookingId, ['stars' => 2])
        ->assertStatus(201)
        ->assertJsonPath('data.kind', 'canceled_booking')
        ->assertJsonPath('data.isVisible', true)
        ->assertJsonPath('data.comment', null);
});

it('refuses a comment or tags on that feedback with VALIDATION_FAILED on the field', function () {
    test()->flushHeaders()->withToken($this->driverToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    rateBooking($this->paxToken, $this->bookingId, ['stars' => 2, 'comment' => 'Cancelled at 6am.'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['comment']]]);

    rateBooking($this->paxToken, $this->bookingId, ['stars' => 2, 'tags' => ['on_time']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['tags']]]);

    // And on an edit, too — but an edit that sends no comment is fine.
    $id = rateBooking($this->paxToken, $this->bookingId, ['stars' => 2])->assertStatus(201)->json('data.id');

    editRating($this->paxToken, $id, ['comment' => 'Cancelled at 6am.'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');

    editRating($this->paxToken, $id, ['stars' => 3])->assertOk()->assertJsonPath('data.stars', 3);
});

/*
|--------------------------------------------------------------------------
| HISTORY-7 — which statuses are rateable
|--------------------------------------------------------------------------
*/

it('lets the passenger rate a completed journey', function () {
    Booking::query()->whereKey($this->bookingId)->update(['status' => 'completed']);

    rateBooking($this->paxToken, $this->bookingId)->assertStatus(201)
        ->assertJsonPath('data.kind', 'completed_ride');
});

it('refuses every other finished status with TRIP_NOT_RATEABLE', function (string $status) {
    Booking::query()->whereKey($this->bookingId)->update(['status' => $status]);

    rateBooking($this->paxToken, $this->bookingId)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'TRIP_NOT_RATEABLE')
        ->assertJsonPath('error.fields.bookingStatus.0', strtoupper($status));

    expect(Rating::count())->toBe(0);
})->with([
    'no_show',
    'expired',
    'cancelled_by_passenger',
    'exited_due_to_driver_delay',
    'pending',
    'confirmed',
]);

/**
 * ⚠️ The one exception today, pinned so it is a decision and not an accident: a journey she LEFT
 * early is rateable, because she was in the car for part of it, and a safety exit is exactly what
 * somebody needs to be able to describe. The 2026-11-01 request asks for COMPLETED only; whether to
 * close this is a product decision, handed to the owner (HISTORY-7).
 */
it('lets the passenger rate a journey she ended early, as a completed ride', function () {
    Booking::query()->whereKey($this->bookingId)->update(['status' => 'ended_early']);

    rateBooking($this->paxToken, $this->bookingId)->assertStatus(201)
        ->assertJsonPath('data.kind', 'completed_ride');
});

it('refuses the driver on a seat he cancelled', function () {
    test()->flushHeaders()->withToken($this->driverToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    rateBooking($this->driverToken, $this->bookingId)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'TRIP_NOT_RATEABLE');
});
