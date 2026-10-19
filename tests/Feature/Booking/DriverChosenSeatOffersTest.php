<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Notification\Models\Notification;

/**
 * The driver picks who on her waiting list gets offered a seat (Feature 03 v5).
 *
 * 🔴 This is v5's **named exception** to the rule the rest of the system follows:
 *
 * > *"Driver manually Resume. **Exception to normal automatic seat-open fanout:** driver chooses
 * > individual, selected subset, or all waiting-list members to offer seats to; may prioritize
 * > previous/recurring passengers. First eligible server-committed acceptance wins. Offers have no
 * > fixed timer while seat/eligibility persists. Driver may withdraw only unaccepted offers."*
 *
 * Everywhere else a freed seat goes out to everybody waiting the moment it frees, and nobody
 * chooses. Resuming is different in kind: the driver is refilling a whole car for weeks of
 * mornings after a pause cancelled all of it, not patching one hole — and v5 lets her favour the
 * women she was already carrying.
 *
 * What her choice does NOT do is give anybody a seat. It decides who may race; the server still
 * decides who wins, under the trip's row lock, exactly as for an automatic fan-out.
 */
beforeEach(function () {
    $this->driverToken = approvedDriver();
    // Captured before any other driver exists, so a later `sole()` cannot pick up her car.
    $this->vehicleId = Vehicle::sole()->id;
    $this->commuteId = readyCommute($this->driverToken, $this->vehicleId, ['seatsTotal' => 1]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
});

/**
 * Somebody the driver has approved onto the waiting list for one specific day.
 *
 * @return array{token: string, requestId: string}
 */
function waitingFor(string $driverToken, string $commuteId, string $tripId, string $phone, string $device): array
{
    $token = verifiedPassenger($phone, device: $device);

    $requestId = requestSeat($token, $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data.id');

    test()->withToken($driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")->assertOk();

    return ['token' => $token, 'requestId' => $requestId];
}

it('offers seats only to the members she chose', function () {
    $chosen = waitingFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    $notChosen = waitingFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/commutes/{$this->commuteId}/waitlist/offers", [
            'seatRequestIds' => [$chosen['requestId']],
        ])
        ->assertOk()
        ->assertJsonPath('data.membersOffered', 1);

    expect(SeatOffer::query()->where('seat_request_id', $chosen['requestId'])->count())->toBe(1)
        // 🔴 The whole point of the exception: the woman she did not choose is not in the race.
        ->and(SeatOffer::query()->where('seat_request_id', $notChosen['requestId'])->count())->toBe(0);

    // And she is told it was a driver's choice, not that a seat happened to free up.
    expect(Notification::query()->where('type', 'seats_offered_by_driver')->count())->toBe(1)
        ->and(Notification::query()->where('type', 'seat_offered')->count())->toBe(0);
});

it('offers to everybody waiting when she says all', function () {
    waitingFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    waitingFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/commutes/{$this->commuteId}/waitlist/offers", ['all' => true])
        ->assertOk()
        ->assertJsonPath('data.membersOffered', 2)
        ->assertJsonPath('data.offersCreated', 2);
});

/**
 * 🔴 An empty body is refused rather than read as one of the two.
 *
 * "These two people" and "everybody on my list" are different decisions about her own car, and a
 * request that says neither must not quietly pick one.
 */
it('refuses a request that names nobody and does not say all', function () {
    waitingFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/commutes/{$this->commuteId}/waitlist/offers", [])
        ->assertStatus(422);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/commutes/{$this->commuteId}/waitlist/offers", [
            'all' => true,
            'seatRequestIds' => [waitingFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2')['requestId']],
        ])
        ->assertStatus(422);

    expect(SeatOffer::query()->count())->toBe(0);
});

/**
 * 🔒 The ids are filtered against her own commute's list, not trusted. Somebody else's waiting
 * member is silently absent rather than refused — telling her "that id is not yours" would answer a
 * question about another driver's commute.
 */
it('ignores a seat request belonging to somebody else’s commute', function () {
    $otherDriver = approvedDriver('01033334444', devicePublicId: 'dev-2', seed: 2);
    $otherCommute = readyCommute($otherDriver, Vehicle::query()->whereKeyNot($this->vehicleId)->sole()->id);

    test()->withToken($otherDriver)->postJson("/api/v1/commutes/{$otherCommute}/publish")->assertOk();

    $otherTrip = ScheduledTrip::query()->where('commute_offer_id', $otherCommute)->orderBy('trip_date')->firstOrFail()->id;
    $theirs = waitingFor($otherDriver, $otherCommute, $otherTrip, '01155667788', 'pax-2');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/commutes/{$this->commuteId}/waitlist/offers", [
            'seatRequestIds' => [$theirs['requestId']],
        ])
        ->assertOk()
        ->assertJsonPath('data.membersOffered', 0);

    expect(SeatOffer::query()->count())->toBe(0);
});

/**
 * 🔴 Choosing who may race is not choosing who wins. Both women she offered the one seat to can
 * try; the server settles it under the trip's lock, and the loser is told somebody was quicker
 * rather than that something went wrong.
 */
it('still lets the server decide the winner when she offers one seat to two people', function () {
    $first = waitingFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    $second = waitingFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/commutes/{$this->commuteId}/waitlist/offers", ['all' => true])
        ->assertOk();

    $firstOffer = SeatOffer::query()->where('seat_request_id', $first['requestId'])->sole();
    $secondOffer = SeatOffer::query()->where('seat_request_id', $second['requestId'])->sole();

    test()->flushHeaders()->withToken($first['token'])
        ->postJson("/api/v1/seat-offers/{$firstOffer->getKey()}/accept")
        ->assertOk();

    test()->flushHeaders()->withToken($second['token'])
        ->postJson("/api/v1/seat-offers/{$secondOffer->getKey()}/accept")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_OFFER_CLOSED');

    expect(Booking::query()->where('status', BookingStatus::Confirmed->value)->count())->toBe(1);
});

/**
 * v5: "Driver may withdraw only unaccepted offers."
 */
it('lets her withdraw an offer nobody has accepted', function () {
    $member = waitingFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/commutes/{$this->commuteId}/waitlist/offers", ['all' => true])
        ->assertOk();

    $offerId = SeatOffer::query()->where('seat_request_id', $member['requestId'])->sole()->getKey();

    test()->withToken($this->driverToken)
        ->deleteJson("/api/v1/driver/seat-offers/{$offerId}")
        ->assertOk()
        ->assertJsonPath('data.status', 'WITHDRAWN');

    // And the woman who was offered it can no longer take it.
    test()->flushHeaders()->withToken($member['token'])
        ->postJson("/api/v1/seat-offers/{$offerId}/accept")
        ->assertStatus(409);
});

/**
 * 🔴 Once accepted it is a booking, and taking that back is a driver cancellation with fees and a
 * reliability impact — not an undo. So the withdraw route refuses it and she has to cancel properly.
 */
it('refuses to withdraw an offer that has already been accepted', function () {
    $member = waitingFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/commutes/{$this->commuteId}/waitlist/offers", ['all' => true])
        ->assertOk();

    $offerId = SeatOffer::query()->where('seat_request_id', $member['requestId'])->sole()->getKey();

    test()->flushHeaders()->withToken($member['token'])
        ->postJson("/api/v1/seat-offers/{$offerId}/accept")
        ->assertOk();

    test()->flushHeaders()->withToken($this->driverToken)
        ->deleteJson("/api/v1/driver/seat-offers/{$offerId}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_OFFER_CLOSED');

    expect(SeatOffer::findOrFail($offerId)->status)->toBe(SeatOfferStatus::Accepted);
});

/**
 * 🔒 A paused commute generates no days and cannot be found, so an offer on one invites somebody to
 * a journey that is not running. Refused with a reason rather than silently producing nothing.
 */
it('refuses to offer seats on a commute that is still paused', function () {
    waitingFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/pause")->assertOk();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/commutes/{$this->commuteId}/waitlist/offers", ['all' => true])
        // 409, the status this code already carries everywhere: the request was well formed and the
        // commute's state is what refuses it.
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_NOT_EDITABLE');

    expect(SeatOffer::query()->count())->toBe(0);
});

/**
 * The waiting list as the driver reads it, with the three signals v5 asks the cards to carry.
 */
it('shows her the waiting list with the previous-passenger label', function () {
    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    // She rode here before: approved, then displaced when the commute was paused.
    approveSeat($this->driverToken, $requestId);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/pause")->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/resume")->assertOk();

    $card = test()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/commutes/{$this->commuteId}/waitlist")
        ->assertOk()
        ->json('data.0');

    expect($card['seatRequestId'])->toBe($requestId)
        // 🔴 The label v5 asks for, so she can prioritise the women she was already carrying.
        ->and($card['isPreviousPassenger'])->toBeTrue()
        ->and($card['displacedReason'])->toBe('commute_paused')
        ->and($card['commitment'])->toBe('trial')
        // A bare boolean, never whose commute or when. See WaitlistMemberResource.
        ->and($card['hasOtherConfirmedCommute'])->toBeFalse()
        // 🔒 And the privacy line has not moved: a public first name, not a full one.
        ->and($card['passenger'])->not->toHaveKey('fullName')
        ->and($card['passenger'])->not->toHaveKey('phone');
});

/**
 * 🔒 A waiting list is a list of women who want to be in a particular car at a particular time. Not
 * this driver's commute, not her business — and a 404 rather than a 403, so the id is not confirmed.
 */
it('hides another driver’s waiting list behind a 404', function () {
    $otherDriver = approvedDriver('01033334444', devicePublicId: 'dev-2', seed: 2);

    test()->withToken($otherDriver)
        ->getJson("/api/v1/driver/commutes/{$this->commuteId}/waitlist")
        ->assertStatus(404);
});
