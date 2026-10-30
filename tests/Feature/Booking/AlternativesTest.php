<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Support\Facades\Storage;

/**
 * "Find another commute": what she could still catch, after a morning was taken away from her
 * (Feature 03 v5 §126–128, §441, §444).
 *
 * > *"When active trip canceled, offer **suggestions** of compatible nearby alternatives, **never
 * > automatically book**."* · *"For already-boarded passengers: alternatives start at actual
 * > stop/current termination location … For confirmed passengers not yet picked up: alternatives
 * > start at originally agreed pickup."*
 *
 * 🔴 The point of the endpoint is that she does not retype a journey while standing at a roadside.
 * So most of this file is about WHERE the server decided to search from, and whether it said so —
 * because "other commutes from here" and "other commutes from your usual stop" are different
 * promises to somebody deciding whether to start walking.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();

    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $this->bookingId = approveSeat(
        $this->driverToken,
        requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->json('data.id'),
    );
});

function alternatives(string $token, string $bookingId)
{
    return test()->withToken($token)->getJson("/api/v1/bookings/{$bookingId}/alternatives");
}

/**
 * 🔴 v5 §241 in its own words: *"allow alternative request while old booking still confirmed."* A
 * woman deciding whether to give up on a driver who has not arrived has to see what else there is
 * BEFORE she lets go of the seat she has.
 */
it('answers while her seat is still confirmed, so she can look before she leaps', function () {
    alternatives($this->paxToken, $this->bookingId)
        ->assertOk()
        ->assertJsonPath('meta.origin.kind', 'AGREED_PICKUP');

    expect(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::Confirmed);
});

/**
 * 🔴 The heart of it: nothing is reserved, requested or held. v5 says so twice, and the reason is
 * not procedural — a woman whose ride just collapsed is the last person who should discover she has
 * been committed to a stranger's car on her behalf.
 */
it('reserves nothing and asks nobody for anything', function () {
    $before = [
        'bookings' => Booking::query()->count(),
        'requests' => SeatRequest::query()->count(),
        'offers' => SeatOffer::query()->count(),
    ];

    alternatives($this->paxToken, $this->bookingId)->assertOk();

    expect(Booking::query()->count())->toBe($before['bookings'])
        ->and(SeatRequest::query()->count())->toBe($before['requests'])
        ->and(SeatOffer::query()->count())->toBe($before['offers']);
});

it('searches from her agreed pickup when the car never reached her', function () {
    underway($this->driverToken, $this->trip->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->trip->id}/cancel-active", ['reason' => 'Engine gave out'])
        ->assertOk();

    $booking = Booking::query()->findOrFail($this->bookingId);

    alternatives($this->paxToken, $this->bookingId)
        ->assertOk()
        ->assertJsonPath('meta.origin.kind', 'AGREED_PICKUP')
        ->assertJsonPath('meta.origin.lat', (float) $booking->pickup_lat)
        ->assertJsonPath('meta.origin.lng', (float) $booking->pickup_lng);
});

/**
 * 🔴 v5 §128's "actual stop/current termination location". She was in the car; where she is now is
 * where the car stopped, which is nowhere near where she got in.
 */
it('searches from where the car actually stopped, for somebody it was carrying', function () {
    underway($this->driverToken, $this->trip->id);
    checkIn($this->driverToken, $this->trip->id, ['bookingId' => $this->bookingId])->assertOk();

    // Half way down the Mehwar, a long way past her gate.
    reportPosition($this->driverToken, $this->trip->id, [[
        'lat' => 30.0300,
        'lng' => 31.0100,
        'recordedAt' => now()->toIso8601String(),
        'accuracyMeters' => 10,
    ]])->assertOk();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->trip->id}/cancel-active", ['reason' => 'Accident on the Mehwar'])
        ->assertOk();

    alternatives($this->paxToken, $this->bookingId)
        ->assertOk()
        ->assertJsonPath('meta.origin.kind', 'WHERE_THE_CAR_STOPPED')
        ->assertJsonPath('meta.origin.lat', 30.03)
        ->assertJsonPath('meta.origin.lng', 31.01);
});

/**
 * 🔴 v5 §129: *"handle unavailable location gracefully without inventing another mandatory step."*
 * A position that was never reported must not turn into a form asking her where she is — that form
 * is the safe-stop picker v5 forbids, with a different label.
 */
it('still answers for somebody it carried when nothing recorded where the car got to', function () {
    underway($this->driverToken, $this->trip->id);
    checkIn($this->driverToken, $this->trip->id, ['bookingId' => $this->bookingId])->assertOk();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->trip->id}/cancel-active", ['reason' => 'Breakdown'])
        ->assertOk();

    $booking = Booking::query()->findOrFail($this->bookingId);

    alternatives($this->paxToken, $this->bookingId)
        ->assertOk()
        /*
         * 🔴 Named as a guess rather than passed off as her position, so a client can offer her
         * the map instead of implying we know. It falls back to her agreed pickup, which is wrong
         * by however far the car carried her — and still better than refusing to answer.
         *
         * ⚠️ This asserts `AGREED_PICKUP_UNVERIFIED` and not `LAST_STOP_REACHED`, and that is the
         * point. The cancellation used to stamp `reached_at` on every stop to clear the itinerary,
         * so this answered `LAST_STOP_REACHED` — pointing at a stop the car had never reached,
         * invented by the cancellation itself and handed back as her location.
         */
        ->assertJsonPath('meta.origin.kind', 'AGREED_PICKUP_UNVERIFIED')
        ->assertJsonPath('meta.origin.lat', (float) $booking->pickup_lat);
});

/**
 * The honest middle case: her position has expired, but the run genuinely did reach a stop beyond
 * her — somebody else was set down — so that is where the search starts.
 */
it('falls back to a stop the car really reached, when one exists', function () {
    $other = verifiedPassenger('01223335555', device: 'pax-6');
    $otherBooking = approveSeat(
        $this->driverToken,
        requestSeat($other, $this->commuteId, [
            'scheduledTripId' => $this->trip->id,
            'pickup' => ['lat' => 30.0600, 'lng' => 31.4000, 'label' => 'Nasr City'],
            'dropoff' => ['lat' => 30.0700, 'lng' => 31.0500, 'label' => 'Mehwar exit'],
        ])->json('data.id'),
    );

    // The stops only exist once she has read her route, which is what she does before driving.
    test()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/commute-occurrences/{$this->trip->id}/navigation-route")->assertOk();

    underway($this->driverToken, $this->trip->id);
    checkIn($this->driverToken, $this->trip->id, ['bookingId' => $this->bookingId])->assertOk();
    checkIn($this->driverToken, $this->trip->id, ['bookingId' => $otherBooking])->assertOk();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->trip->id}/status", ['status' => 'IN_PROGRESS'])->assertOk();

    // The other woman is set down; that stop is now genuinely reached.
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->trip->id}/drop-off", ['bookingId' => $otherBooking])
        ->assertOk();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->trip->id}/cancel-active", ['reason' => 'Flat tyre'])
        ->assertOk();

    alternatives($this->paxToken, $this->bookingId)
        ->assertOk()
        ->assertJsonPath('meta.origin.kind', 'LAST_STOP_REACHED')
        ->assertJsonPath('meta.origin.lat', 30.07);
});

/**
 * 🔴 The case the endpoint exists for, and the one the others cannot prove: a real alternative
 * comes back. Without this, an implementation that answered an empty list for everybody would pass
 * every other test in this file.
 *
 * 🔴 v5 §444: *"alternatives list shows **car type** and only commutes that still have free
 * seats."* Both are asserted — the free-seats half is the search's own hard filter, and the car is
 * on the result card because a woman choosing between two strangers' cars at a roadside is told
 * which car to look for.
 */
it('returns another driver’s commute she could actually catch, with the car on the card', function () {
    // `seed` because the licence and national id are unique: a second driver built from the same
    // one is refused as a duplicate of the first.
    $otherDriver = approvedDriver(phone: '01099998888', devicePublicId: 'driver-2', seed: 2);

    $otherCommute = readyCommute($otherDriver, Vehicle::query()->latest('id')->firstOrFail()->id);

    // Starting where she stands: the Ring Road exit her own booking agreed on. Stage two of the
    // search is a walking-distance filter, so a commute beginning at the far end of the same road
    // is not an alternative for somebody on foot.
    saveRoute($otherDriver, $otherCommute, [
        'origin' => ['lat' => 30.0624, 'lng' => 31.3614, 'address' => 'Ring Road, Rehab exit'],
        'destination' => ['lat' => 30.0684, 'lng' => 31.1015, 'address' => 'Mehwar, Sheikh Zayed'],
    ])->assertOk();

    test()->withToken($otherDriver)->postJson("/api/v1/commutes/{$otherCommute}/publish")->assertOk();

    alternatives($this->paxToken, $this->bookingId)
        ->assertOk()
        ->assertJsonPath('meta.matched', 1)
        ->assertJsonPath('data.0.commuteId', $otherCommute)
        ->assertJsonPath('data.0.vehicle.make', fn (?string $make) => $make !== null && $make !== '')
        ->assertJsonPath('data.0.seatsAvailable', fn (int $seats) => $seats > 0);
});

/**
 * 🔴 It draws on the SAME allowance as `GET /search/commutes`, because it runs the same engine and
 * the engine ends in paid routing-provider work. Two separate allowances would let a caller
 * alternate between the endpoints and spend double — one limit with a door beside it.
 *
 * ⚠️ This was declared in `#[ApiErrors]` before it was enforced: the endpoint advertised
 * `TOO_MANY_REQUESTS` and had no limit at all.
 */
it('spends from the same search allowance, so neither endpoint is a way round the other', function () {
    config(['rafeeq.rate_limits_enabled' => true, 'rafeeq.matching.searches_per_user_per_minute' => 2]);

    alternatives($this->paxToken, $this->bookingId)->assertOk();

    test()->withToken($this->paxToken)->getJson('/api/v1/search/commutes?'.http_build_query([
        'origin' => ['lat' => 30.0624, 'lng' => 31.3614],
        'destination' => ['lat' => 30.0684, 'lng' => 31.1015],
        'daysMask' => 62,
        'arrivalWindowStart' => '07:00:00',
        'arrivalWindowEnd' => '09:00:00',
        'maxWalkMinutes' => 15,
        'maxDetourMinutes' => 15,
    ]))->assertOk();

    // Third call of the minute, whichever door it came through.
    alternatives($this->paxToken, $this->bookingId)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
});

it('is hers — the driver cannot read the list of cars her passenger is being offered', function () {
    alternatives($this->driverToken, $this->bookingId)->assertNotFound();
});

it('is nobody else’s either', function () {
    alternatives(verifiedPassenger('01223338888', device: 'pax-3'), $this->bookingId)->assertNotFound();
});

/**
 * A finished journey needs no alternative. Not an error worth its own code — the booking is simply
 * not one this endpoint is about.
 */
it('has nothing to say about a journey that already happened', function () {
    // Collected, carried, set down — `journeyCompleted` cannot be reused because the check-in has
    // to land between its start and its finish.
    underway($this->driverToken, $this->trip->id);
    checkIn($this->driverToken, $this->trip->id, ['bookingId' => $this->bookingId])->assertOk();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->trip->id}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->trip->id}/complete")->assertOk();

    alternatives($this->paxToken, $this->bookingId)->assertNotFound();
});

/**
 * ⚠️ Rafeeq does not dispatch, and this is where that shows. The search only returns occurrences
 * that have not departed, so an empty list is the honest answer when nothing else runs this way —
 * and the client offers to save a demand, exactly as the ordinary search does.
 */
it('answers an empty list rather than an error when nothing else runs her way', function () {
    alternatives($this->paxToken, $this->bookingId)
        ->assertOk()
        ->assertJsonPath('meta.matched', 0)
        ->assertJsonPath('data', []);
});

/**
 * The one thing it cannot infer. Where she was going does not change because the journey stopped,
 * and a search without a destination would rank every car heading vaguely her way.
 */
it('refuses rather than guess when her booking has no agreed drop-off', function () {
    Booking::query()->whereKey($this->bookingId)->update(['dropoff_lat' => null, 'dropoff_lng' => null]);

    alternatives($this->paxToken, $this->bookingId)->assertNotFound();
});
