<?php

use App\Domains\Booking\Actions\AcceptSeatOfferAction;
use App\Domains\Booking\Actions\OfferSeatToWaitlistAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Storage;

/**
 * The waiting list, and what happens when a seat comes free (Master Plan §889:
 * "Waitlist + الترتيب").
 *
 * 🔴 The rule the whole feature turns on: a freed seat promotes the next person into
 * the DRIVER'S INBOX, it does not book them. In Rafeeq the driver decides who rides
 * with them, and seating a stranger automatically because somebody else cancelled
 * would put a person in their car without them ever saying yes.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->first();

    // One passenger seated, and then the day is filled so everybody after her waits.
    $this->riderToken = verifiedPassenger('01112223344', device: 'pax-1');

    $requestId = requestSeat($this->riderToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    $this->bookingId = approveSeat($this->driverToken, $requestId);

    $this->trip->forceFill(['seats_taken' => 3])->save();
});

/**
 * Joins the queue and returns the position it was given.
 */
function joinWaitlist(string $phone, string $device, string $commuteId, string $tripId): array
{
    $token = verifiedPassenger($phone, device: $device);

    $data = requestSeat($token, $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data');

    return ['token' => $token, 'id' => $data['id'], 'position' => $data['waitlistPosition']];
}

it('gives each waiting passenger their own place in the queue', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    expect($first['position'])->toBe(1)
        ->and($second['position'])->toBe(2);
});

/**
 * 🔴 The heart of it, and it changed: a freed seat is OFFERED to everybody eligible, and the first
 * acceptance wins (Feature 03 v3, product decision 2026-10-16).
 *
 * The previous model promoted one person to `pending` and still needed the driver to approve her,
 * which meant a freed seat could sit empty for up to 48 hours waiting on one reply while four
 * people waited behind her. Everybody on this list is already driver-approved — she approved them
 * when she waitlisted them — so acceptance IS the seat, and the others are told somebody was
 * quicker rather than being refused.
 */
it('offers a freed seat to everybody waiting, without booking anybody', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel", ['reason' => 'Plans changed'])
        ->assertOk();

    $offers = SeatOffer::query()->where('scheduled_trip_id', $this->trip->id)->get();

    expect($offers)->toHaveCount(2)
        ->and($offers->pluck('status')->unique()->all())->toBe([SeatOfferStatus::Open])
        ->and($offers->pluck('seat_request_id')->sort()->values()->all())
        ->toBe(collect([$first['id'], $second['id']])->sort()->values()->all());

    // Still waiting, and still nobody's seat: an offer promises nothing until it is accepted.
    expect(SeatRequest::query()->whereKey($first['id'])->sole()->status)
        ->toBe(SeatRequestStatus::Waitlisted)
        ->and(Booking::query()->where('status', 'confirmed')->count())->toBe(0);

    // One cancellation is one event, however many people it reached.
    expect($offers->pluck('release_event_id')->unique())->toHaveCount(1);
});

/**
 * 🔴 One seat, one winner — the guarantee the old single-promotion model got by never offering
 * twice, and which this model has to earn under a lock.
 */
it('gives the seat to the first acceptance and tells the rest it is gone', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);
    $third = joinWaitlist('01222220003', 'pax-4', $this->commuteId, $this->trip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    $winner = SeatOffer::query()->where('seat_request_id', $second['id'])->sole();

    app(AcceptSeatOfferAction::class)->execute($winner);

    $offers = SeatOffer::query()->get()->keyBy('seat_request_id');

    expect($offers[$second['id']]->status)->toBe(SeatOfferStatus::Accepted)
        /*
         * The other two are `taken_by_other`, not `closed` and not a refusal. Somebody was quicker,
         * which is what a waiting list means — and the word has to be the true one, because a
         * person reading her own history should be able to tell that apart from a seat that
         * evaporated.
         */
        ->and($offers[$first['id']]->status)->toBe(SeatOfferStatus::TakenByOther)
        ->and($offers[$third['id']]->status)->toBe(SeatOfferStatus::TakenByOther);

    /*
     * The winner is booked without a second approval, and only she is. Counted per passenger
     * rather than globally: the fixture already contains confirmed bookings, so a global count
     * would be asserting about the setup instead of about the race.
     */
    $winnerRequest = SeatRequest::query()->whereKey($second['id'])->sole();

    expect($winnerRequest->status)->toBe(SeatRequestStatus::Approved)
        ->and(Booking::query()->where('passenger_user_id', $winnerRequest->passenger_user_id)->count())->toBe(1)
        ->and(Booking::query()->whereIn('passenger_user_id', [
            SeatRequest::query()->whereKey($first['id'])->sole()->passenger_user_id,
            SeatRequest::query()->whereKey($third['id'])->sole()->passenger_user_id,
        ])->count())->toBe(0);
});

/**
 * 🔒 The race, decided by the server rather than by whose phone was quicker.
 *
 * v3 is explicit that accepting "first accept" does **not** authorise first-device-tap priority:
 * who reaches us first depends on her signal and her distance from the data centre, neither of
 * which is a fair basis for giving away a seat. So the loser is refused under the same lock that
 * seat approval uses, with a code that says what happened.
 */
it('refuses a second acceptance of the same seat, as a state refusal and not a fault', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    app(AcceptSeatOfferAction::class)->execute(
        SeatOffer::query()->where('seat_request_id', $first['id'])->sole()
    );

    $late = SeatOffer::query()->where('seat_request_id', $second['id'])->sole();

    expect(fn () => app(AcceptSeatOfferAction::class)->execute($late))
        ->toThrow(DomainException::class);

    // And no seat was invented for her.
    $loser = SeatRequest::query()->whereKey($second['id'])->sole();

    expect(Booking::query()->where('passenger_user_id', $loser->passenger_user_id)->count())->toBe(0)
        ->and($loser->status)->toBe(SeatRequestStatus::Waitlisted);
});

/**
 * Position survives as a FORECAST, which was the product decision: the design shows
 * "Position 1 of 2 · Est. seat within ~1 week", and that is kept as an estimate rather than a
 * promise of order. Nothing in the allocation path reads it.
 */
it('still keeps the queue numbered, even though the seat is a race', function () {
    joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    // The person at position 2 wins, which a strict queue would have forbidden.
    app(AcceptSeatOfferAction::class)->execute(
        SeatOffer::query()->where('seat_request_id', $second['id'])->sole()
    );

    // She leaves the list, so the one behind her moves up to 1.
    expect(SeatRequest::query()->where('status', SeatRequestStatus::Waitlisted->value)
        ->pluck('waitlist_position')->all())->toBe([1]);
});

/**
 * Nobody gets two LIVE chances at one morning. A retried job or an overlapping run must not put two
 * open offers for the same day in front of one person.
 *
 * ⚠️ Note what this does **not** prove, because for a while it was believed to. Calling the fan-out
 * again mints a new `release_event_id`, so this is not a replay of one release — it is a second
 * release finding her first offer still open. True retry-safety (the same release recorded twice) is
 * guarded by the unique `(seat_request_id, release_event_id)` key and is asserted in
 * `tests/Feature/Outbox`.
 */
it('does not offer the same seat to the same person twice', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    // The fan-out runs again, as a retry would.
    $offered = app(OfferSeatToWaitlistAction::class)->forTrip($this->trip->refresh(), 1);

    expect($offered)->toBe(0)
        ->and(SeatOffer::query()->where('seat_request_id', $first['id'])->count())->toBe(1);
});

/**
 * 🔴 But a second seat freeing on the same day IS offered — and until 2026-10-23 it was not.
 *
 * `seat_offers` was unique on `(scheduled_trip_id, seat_request_id)` under a comment that read "one
 * **open** offer per person per journey". The index was stronger than the comment: one offer per
 * person per journey for ever. So once a day's first fan-out had closed, every later freeing on that
 * day was silently offered to nobody — `insertOrIgnore` ignored every row and reported success — and
 * the car went out with an empty seat while approved women waited for exactly it.
 *
 * The key now guards the release, which is the thing that must not repeat. See the migration
 * `key_seat_offers_by_release_not_journey`.
 */
it('offers a second seat freeing on the same day to the people whose first offer closed', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    // Seat one frees, and the quicker of the two takes it.
    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    app(AcceptSeatOfferAction::class)->execute(
        SeatOffer::query()->where('seat_request_id', $first['id'])->sole()
    );

    // So she is closed `taken_by_other` and still waiting — which is what a waiting list means.
    expect(SeatOffer::query()->where('seat_request_id', $second['id'])->sole()->status)
        ->toBe(SeatOfferStatus::TakenByOther)
        ->and(SeatRequest::query()->whereKey($second['id'])->sole()->status)
        ->toBe(SeatRequestStatus::Waitlisted);

    // Now a second seat frees on the very same morning.
    $winner = Booking::query()
        ->where('seat_request_id', $first['id'])
        ->where('status', BookingStatus::Confirmed->value)
        ->sole();

    test()->flushHeaders()->withToken($first['token'])
        ->patchJson("/api/v1/bookings/{$winner->getKey()}/cancel")->assertOk();

    $offers = SeatOffer::query()->where('seat_request_id', $second['id'])->get();

    // 🔴 Two offers for one day, from two different releases, and the live one is the new one.
    expect($offers)->toHaveCount(2)
        ->and($offers->pluck('release_event_id')->unique())->toHaveCount(2)
        ->and($offers->where('status', SeatOfferStatus::Open)->count())->toBe(1);
});

it('does not promote somebody waiting for a different day', function () {
    $otherTrip = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->where('id', '!=', $this->trip->id)
        ->orderBy('trip_date')
        ->first();

    $otherTrip->forceFill(['seats_taken' => 3])->save();

    $waiting = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $otherTrip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    // A seat freeing on Sunday is no help to somebody waiting for Monday.
    expect(SeatRequest::query()->whereKey($waiting['id'])->sole()->status)
        ->toBe(SeatRequestStatus::Waitlisted);
});

it('refuses to lengthen a queue that is already a false hope', function () {
    config(['rafeeq.booking.max_waitlist_size' => 2]);

    joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    $third = verifiedPassenger('01222220003', device: 'pax-4');

    requestSeat($third, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_WAITLIST_FULL');
});

/**
 * Somebody leaving the middle of the queue must not leave everybody behind them
 * reading a position that is further back than where they stand.
 */
it('closes the gap when a waiting passenger withdraws', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);
    $third = joinWaitlist('01222220003', 'pax-4', $this->commuteId, $this->trip->id);

    test()->withToken($second['token'])->deleteJson("/api/v1/seat-requests/{$second['id']}")->assertOk();

    expect(SeatRequest::query()->whereKey($first['id'])->sole()->waitlist_position)->toBe(1)
        ->and(SeatRequest::query()->whereKey($third['id'])->sole()->waitlist_position)->toBe(2);
});

/**
 * The position is assigned from the highest one in use, not from how many people are
 * waiting: with three at 1, 2, 3, a count-based number would hand the next arrival
 * position 3 — the place somebody else is already standing in.
 */
it('never hands out a position somebody else already holds', function () {
    joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);
    joinWaitlist('01222220003', 'pax-4', $this->commuteId, $this->trip->id);

    // Removed straight in the database, so no renumbering runs — the raw shape the
    // count-based version got wrong.
    SeatRequest::query()->whereKey($second['id'])->update([
        'status' => SeatRequestStatus::Withdrawn->value,
        'waitlist_position' => null,
    ]);

    $fourth = joinWaitlist('01222220004', 'pax-5', $this->commuteId, $this->trip->id);

    $positions = SeatRequest::query()
        ->where('status', SeatRequestStatus::Waitlisted->value)
        ->pluck('waitlist_position');

    expect($fourth['position'])->toBe(4)
        ->and($positions->count())->toBe($positions->unique()->count());
});

it('does not offer the seat to an expired request', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    // The first one gave up on it long ago.
    SeatRequest::query()->whereKey($first['id'])->update(['expires_at' => now()->subHour()]);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    /*
     * 🔴 She is skipped entirely rather than offered and then refused. An expired request is not a
     * person who is still hoping, and sending her "a seat just opened up" would be waking somebody
     * up about a commute she stopped waiting for.
     */
    expect(SeatOffer::query()->where('seat_request_id', $first['id'])->exists())->toBeFalse()
        ->and(SeatOffer::query()->where('seat_request_id', $second['id'])->sole()->status)
        ->toBe(SeatOfferStatus::Open);
});

it('does not promote somebody who needs more seats than came free', function () {
    $token = verifiedPassenger('01222220001', device: 'pax-2');

    // Two seats wanted; one comes free.
    $request = requestSeat($token, $this->commuteId, [
        'scheduledTripId' => $this->trip->id,
        'seats' => 2,
    ])->assertStatus(201)->json('data');

    expect($request['status'])->toBe('WAITLISTED');

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    expect(SeatRequest::query()->whereKey($request['id'])->sole()->status)
        ->toBe(SeatRequestStatus::Waitlisted);
});

it('lets the driver approve a promoted request like any other', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    $bookingId = approveSeat($this->driverToken, $first['id']);

    $booking = Booking::query()->whereKey($bookingId)->sole();

    expect($booking->passenger_user_id)
        ->toBe(User::query()->where('phone_e164', '+201222220001')->sole()->id)
        ->and($this->trip->refresh()->seats_taken)->toBe(3);
});

/**
 * 🔴 The driver's third answer — screen 28's `Waitlist` button.
 *
 * Not a yes and not a no: "I would take you, but not this week." Before it, the driver's
 * only alternative to approving was declining, so a full week meant refusing somebody they
 * had already judged suitable — and that person's only way back was to ask again.
 */
it('lets the driver park a request instead of refusing it', function () {
    // A day with room, so this is the driver choosing rather than the day being full.
    $this->trip->forceFill(['seats_taken' => 0])->save();

    $token = verifiedPassenger('01222220009', device: 'pax-9');

    $requestId = requestSeat($token, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'PENDING')
        ->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist", ['note' => 'Full this week — first next week.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'WAITLISTED')
        // A place in the queue, not a null: the position is what the passenger is shown.
        ->assertJsonPath('data.waitlistPosition', 1)
        ->assertJsonPath('data.responseNote', 'Full this week — first next week.');

    // Parked, not booked.
    expect(Booking::query()->where('seat_request_id', $requestId)->exists())->toBeFalse();
});

it('gives a parked request a fresh window, since the driver just answered', function () {
    $this->trip->forceFill(['seats_taken' => 0])->save();

    $token = verifiedPassenger('01222220009', device: 'pax-9');

    $requestId = requestSeat($token, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    SeatRequest::query()->whereKey($requestId)->update(['expires_at' => now()->addHour()]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")->assertOk();

    // The 48 hours it had were for the driver to answer, and they did.
    expect(SeatRequest::query()->whereKey($requestId)->sole()->expires_at
        ->isAfter(now()->addHours((int) config('rafeeq.booking.request_expiry_hours') - 1)))->toBeTrue();
});

it('refuses to park a request that is already answered', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);

    // Already waiting — there is nowhere to park it.
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$first['id']}/waitlist")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_NOT_PENDING');
});

it('refuses to park a request on somebody else commute', function () {
    $this->trip->forceFill(['seats_taken' => 0])->save();

    $token = verifiedPassenger('01222220009', device: 'pax-9');

    $requestId = requestSeat($token, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223339999', devicePublicId: 'driver-2', seed: 2);

    // 404, not 403: a 403 would confirm the request exists.
    test()->withToken($otherDriver)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")
        ->assertStatus(404);
});

it('refuses to park anybody once the queue is full', function () {
    config(['rafeeq.booking.max_waitlist_size' => 1]);

    joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);

    $this->trip->forceFill(['seats_taken' => 0])->save();

    $token = verifiedPassenger('01222220009', device: 'pax-9');

    $requestId = requestSeat($token, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_WAITLIST_FULL');
});

/**
 * The endpoints a waiting passenger actually uses.
 */
it('lets her see and take the seat over HTTP, and refuses the one who was slower', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    $firstToken = $first['token'];
    $secondToken = $second['token'];

    // She sees exactly her own offer — never the other person's.
    $listed = test()->withToken($firstToken)->getJson('/api/v1/seat-offers')
        ->assertOk()->json('data');

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['status'])->toBe('OPEN')
        ->and($listed[0]['seatRequestId'])->toBe($first['id'])
        // 🔒 No position, no "first in line", nothing about who else is racing.
        ->and($listed[0])->not->toHaveKey('position');

    test()->withToken($firstToken)
        ->postJson("/api/v1/seat-offers/{$listed[0]['id']}/accept")
        ->assertOk()
        ->assertJsonPath('data.status', 'CONFIRMED');

    // The slower one is told somebody was quicker — a 409 with the reason, not a 500.
    $hers = test()->withToken($secondToken)->getJson('/api/v1/seat-offers')
        ->assertOk()->json('data.0');

    test()->withToken($secondToken)
        ->postJson("/api/v1/seat-offers/{$hers['id']}/accept")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_OFFER_CLOSED');
});

/**
 * 🔒 Somebody else's offer is a 404, not a 403: a 403 would confirm the id exists, which turns the
 * endpoint into an oracle for who is waiting on which commute.
 */
it('answers 404 for an offer that is not hers', function () {
    $first = joinWaitlist('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    joinWaitlist('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    test()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    $hers = SeatOffer::query()->where('seat_request_id', $first['id'])->sole();

    $stranger = verifiedPassenger('01222229999', device: 'pax-stranger');

    test()->withToken($stranger)
        ->postJson("/api/v1/seat-offers/{$hers->id}/accept")
        ->assertStatus(404);
});
