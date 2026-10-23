<?php

use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\StandbyAvailability;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Reliability\Models\ReliabilityEvent;
use Illuminate\Support\Facades\Storage;

/**
 * The two things a waiting passenger can say, neither of which costs her her place
 * (Feature 03 v5 §8).
 *
 * > *"`POST /seat-offers/{id}/decline` — Only declines this offer, doesn't remove membership."*
 * > *"`Available Today` / `Not Available Today` on standby management; reversible repeatedly.
 * > Turning unavailable excludes the member from today's automatic offers, preserves future
 * > recurring membership; returning available doesn't restore closed offers. No penalty."*
 *
 * 🔴 Every test here is about what does **not** happen. Both controls are easy to implement
 * helpfully and wrong: dropping her from the list when she says no, charging her reliability for
 * being unavailable, clearing the flag on somebody's read, reopening closed offers when she comes
 * back. Each of those is a line somebody could add believing it was tidier, and each would make a
 * single inconvenient Tuesday cost a woman a place she waited weeks for.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();

    // One rider seated and the day filled, so anybody asking afterwards is put on the list.
    $this->riderToken = verifiedPassenger('01112223344', device: 'pax-1');

    $seated = requestSeat($this->riderToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    $this->bookingId = approveSeat($this->driverToken, $seated);

    $this->trip->forceFill(['seats_taken' => 3])->save();

    // And one woman waiting for it.
    $this->waiterToken = verifiedPassenger('01222220001', device: 'pax-2');

    $this->waiterRequestId = requestSeat($this->waiterToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'WAITLISTED')
        ->json('data.id');
});

/**
 * Frees the seat for real, which is what produces the offers.
 */
function releaseTheSeat(string $riderToken, string $bookingId): void
{
    test()->flushHeaders()->withToken($riderToken)
        ->patchJson("/api/v1/bookings/{$bookingId}/cancel")->assertOk();
}

/*
|--------------------------------------------------------------------------
| Declining one offer
|--------------------------------------------------------------------------
*/

/**
 * 🔴 The whole specification, in one assertion pair: the offer ends, the membership does not.
 */
it('closes the offer she declined and leaves her on the waiting list', function () {
    releaseTheSeat($this->riderToken, $this->bookingId);

    $offer = SeatOffer::query()->where('seat_request_id', $this->waiterRequestId)->sole();

    test()->flushHeaders()->withToken($this->waiterToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/decline")
        ->assertOk()
        /*
         * `DECLINED`, not `CLOSED`. Her own decision is the only closure here that is, and a woman
         * reading her own history should see that she turned a Tuesday down rather than a list in
         * which every seat she was ever offered appears to have fallen through.
         */
        ->assertJsonPath('data.status', 'DECLINED');

    $membership = SeatRequest::query()->whereKey($this->waiterRequestId)->sole();

    expect($membership->status)->toBe(SeatRequestStatus::Waitlisted)
        // Her place is where it was. She wants a seat in this car; she could not take this one.
        ->and($membership->waitlist_position)->toBe(1);
});

/**
 * And she is offered the next one, which is the point of having stayed on the list.
 *
 * 🔴 This test is also a regression guard for the bug that `decline` uncovered: `seat_offers` was
 * unique on `(scheduled_trip_id, seat_request_id)`, so a second seat freeing on a day whose first
 * fan-out had already closed offered the seat to NOBODY. See the migration
 * `key_seat_offers_by_release_not_journey`.
 */
it('offers her the next seat that frees after she declined one', function () {
    releaseTheSeat($this->riderToken, $this->bookingId);

    $first = SeatOffer::query()->where('seat_request_id', $this->waiterRequestId)->sole();

    test()->flushHeaders()->withToken($this->waiterToken)
        ->postJson("/api/v1/seat-offers/{$first->getKey()}/decline")->assertOk();

    // A second seat frees on the same day.
    $second = verifiedPassenger('01222220002', device: 'pax-3');

    $this->trip->forceFill(['seats_taken' => 2])->save();

    $requestId = requestSeat($second, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    $bookingId = approveSeat($this->driverToken, $requestId);

    test()->flushHeaders()->withToken($second)
        ->patchJson("/api/v1/bookings/{$bookingId}/cancel")->assertOk();

    $offers = SeatOffer::query()->where('seat_request_id', $this->waiterRequestId)->get();

    expect($offers)->toHaveCount(2)
        ->and($offers->where('status', SeatOfferStatus::Open)->count())->toBe(1);
});

/**
 * Declining the same offer twice is a state refusal, not a silent success — and it carries which
 * ending it actually had, because by then somebody may have accepted it.
 */
it('refuses to decline an offer that has already ended', function () {
    releaseTheSeat($this->riderToken, $this->bookingId);

    $offer = SeatOffer::query()->where('seat_request_id', $this->waiterRequestId)->sole();

    test()->flushHeaders()->withToken($this->waiterToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/decline")->assertOk();

    test()->flushHeaders()->withToken($this->waiterToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/decline")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_OFFER_CLOSED')
        ->assertJsonPath('error.fields.status.0', 'declined');
});

/**
 * 🔒 And nobody can decline anybody else's seat — a 404, never a 403, because a 403 would confirm
 * the id exists and turn this into an oracle for who is waiting on which commute.
 */
it('hides somebody else offer behind a 404', function () {
    releaseTheSeat($this->riderToken, $this->bookingId);

    $offer = SeatOffer::query()->where('seat_request_id', $this->waiterRequestId)->sole();

    $stranger = verifiedPassenger('01299887766', device: 'pax-9');

    test()->flushHeaders()->withToken($stranger)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/decline")
        ->assertStatus(404);

    expect(SeatOffer::query()->whereKey($offer->getKey())->sole()->status)
        ->toBe(SeatOfferStatus::Open);
});

/**
 * 🔴 A decline is hers alone. Nothing is written against her, which is the difference between
 * declining a seat she never held and letting somebody down.
 */
it('costs her nothing', function () {
    releaseTheSeat($this->riderToken, $this->bookingId);

    $offer = SeatOffer::query()->where('seat_request_id', $this->waiterRequestId)->sole();

    test()->flushHeaders()->withToken($this->waiterToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/decline")->assertOk();

    $her = SeatRequest::query()->whereKey($this->waiterRequestId)->sole()->passenger_user_id;

    expect(ReliabilityEvent::query()->where('user_id', $her)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| "Not available today"
|--------------------------------------------------------------------------
*/

it('records that she cannot ride today, and says so on her membership', function () {
    test()->flushHeaders()->withToken($this->waiterToken)
        ->patchJson("/api/v1/seat-requests/{$this->waiterRequestId}/availability", ['available' => false])
        ->assertOk()
        ->assertJsonPath('data.availableToday', false)
        // Untouched by the toggle: she is still a member, still in position 1.
        ->assertJsonPath('data.status', 'WAITLISTED')
        ->assertJsonPath('data.waitlistPosition', 1);
});

it('is reversible as often as she likes', function () {
    foreach ([false, true, false, true] as $available) {
        test()->flushHeaders()->withToken($this->waiterToken)
            ->patchJson("/api/v1/seat-requests/{$this->waiterRequestId}/availability", ['available' => $available])
            ->assertOk()
            ->assertJsonPath('data.availableToday', $available);
    }

    expect(SeatRequest::query()->whereKey($this->waiterRequestId)->sole()->unavailable_on)->toBeNull();
});

/**
 * 🔴 The reason the whole thing exists: she is not offered today's seat.
 */
it('leaves her out of today’s fan-out', function () {
    // The day the seat will free on, in the commute's own local terms.
    $her = SeatRequest::query()->whereKey($this->waiterRequestId)->sole();

    $her->forceFill(['unavailable_on' => $this->trip->trip_date->toDateString()])->save();

    releaseTheSeat($this->riderToken, $this->bookingId);

    expect(SeatOffer::query()->where('seat_request_id', $this->waiterRequestId)->count())->toBe(0);
});

/**
 * 🔴 And the reason it is a date rather than the flag v5's sketch suggests: a recurring member who
 * is out on Tuesday is still offered Wednesday, from the same row, with nothing to reset.
 *
 * A boolean would have needed a nightly job to clear it — a second mechanism whose failure is
 * silent, and whose symptom on the morning it does not run is an empty car.
 */
it('still offers her every other day', function () {
    $her = SeatRequest::query()->whereKey($this->waiterRequestId)->sole();

    // She is waiting for the whole commute rather than one named day, as a recurring member is.
    $her->forceFill([
        'scheduled_trip_id' => null,
        'unavailable_on' => $this->trip->trip_date->toDateString(),
    ])->save();

    $another = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->whereKeyNot($this->trip->getKey())
        ->orderBy('trip_date')
        ->firstOrFail();

    // A seat frees on the day she IS available for.
    $other = verifiedPassenger('01222220002', device: 'pax-3');

    $requestId = requestSeat($other, $this->commuteId, [
        'scheduledTripId' => $another->getKey(),
    ])->assertStatus(201)->json('data.id');

    $bookingId = approveSeat($this->driverToken, $requestId);

    test()->flushHeaders()->withToken($other)
        ->patchJson("/api/v1/bookings/{$bookingId}/cancel")->assertOk();

    $offers = SeatOffer::query()->where('seat_request_id', $this->waiterRequestId)->get();

    expect($offers)->toHaveCount(1)
        ->and($offers->first()->scheduled_trip_id)->toBe($another->getKey());
});

/**
 * ⚠️ A declaration about a day that has passed means nothing, and it is reported as nothing without
 * being cleared. A read that wrote would make the state depend on who looked at it last, and the
 * row is evidence of what she said on the day she said it.
 */
it('treats yesterday’s declaration as spent', function () {
    $her = SeatRequest::query()->whereKey($this->waiterRequestId)->sole();

    $her->forceFill(['unavailable_on' => now()->subDays(3)->toDateString()])->save();

    test()->flushHeaders()->withToken($this->waiterToken)
        ->getJson('/api/v1/seat-requests')
        ->assertOk()
        ->assertJsonPath('data.0.availableToday', true);

    // Reported as available, and the row still says what she said.
    expect(SeatRequest::query()->whereKey($this->waiterRequestId)->sole()->unavailable_on)->not->toBeNull();
});

/**
 * ⚠️ Coming back does not undo what happened while she was away. v5: returning available
 * "doesn't restore closed offers" — those seats belong to somebody else now, and showing her one
 * would be showing her another woman's seat.
 */
it('does not reopen an offer that closed while she was away', function () {
    releaseTheSeat($this->riderToken, $this->bookingId);

    $offer = SeatOffer::query()->where('seat_request_id', $this->waiterRequestId)->sole();

    test()->flushHeaders()->withToken($this->waiterToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/decline")->assertOk();

    test()->flushHeaders()->withToken($this->waiterToken)
        ->patchJson("/api/v1/seat-requests/{$this->waiterRequestId}/availability", ['available' => false])
        ->assertOk();

    test()->flushHeaders()->withToken($this->waiterToken)
        ->patchJson("/api/v1/seat-requests/{$this->waiterRequestId}/availability", ['available' => true])
        ->assertOk();

    expect(SeatOffer::query()->whereKey($offer->getKey())->sole()->status)
        ->toBe(SeatOfferStatus::Declined);
});

it('costs her nothing either', function () {
    test()->flushHeaders()->withToken($this->waiterToken)
        ->patchJson("/api/v1/seat-requests/{$this->waiterRequestId}/availability", ['available' => false])
        ->assertOk();

    $her = SeatRequest::query()->whereKey($this->waiterRequestId)->sole()->passenger_user_id;

    expect(ReliabilityEvent::query()->where('user_id', $her)->count())->toBe(0);
});

/**
 * The switch only means something on a membership. Accepting it on a request still waiting for the
 * driver would store a date that changed nothing while the screen showed her as excluded.
 */
it('refuses the toggle on a request that is not on a waiting list', function () {
    $this->trip->forceFill(['seats_taken' => 0])->save();

    $pending = verifiedPassenger('01222220003', device: 'pax-4');

    $requestId = requestSeat($pending, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'PENDING')
        ->json('data.id');

    test()->flushHeaders()->withToken($pending)
        ->patchJson("/api/v1/seat-requests/{$requestId}/availability", ['available' => false])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_NOT_WAITLISTED')
        ->assertJsonPath('error.fields.status.0', 'pending');
});

/**
 * 🔒 Hers alone, behind a 404.
 */
it('hides somebody else membership behind a 404', function () {
    $stranger = verifiedPassenger('01299887766', device: 'pax-9');

    test()->flushHeaders()->withToken($stranger)
        ->patchJson("/api/v1/seat-requests/{$this->waiterRequestId}/availability", ['available' => false])
        ->assertStatus(404);

    expect(SeatRequest::query()->whereKey($this->waiterRequestId)->sole()->unavailable_on)->toBeNull();
});

/**
 * 🔴 The server decides which day "today" is, and it does so in the commute's own timezone.
 *
 * Read in UTC instead, a woman tapping the switch at 1 a.m. Cairo before a 7 a.m. run would have
 * recorded YESTERDAY: the flag would match nothing, she would be offered the seat she had just
 * declined, and the only visible symptom would be the app apparently ignoring her.
 */
it('records the commute’s local day, not the server’s', function () {
    $her = SeatRequest::query()->whereKey($this->waiterRequestId)->sole();

    // 23:30 UTC is already tomorrow in Cairo.
    $this->travelTo(now()->setTime(23, 30));

    expect(StandbyAvailability::todayFor($her)->toDateString())
        ->toBe(now('Africa/Cairo')->toDateString())
        ->not->toBe(now('UTC')->toDateString());
});

it('requires an explicit answer', function () {
    test()->flushHeaders()->withToken($this->waiterToken)
        ->patchJson("/api/v1/seat-requests/{$this->waiterRequestId}/availability", [])
        ->assertStatus(422);
});
