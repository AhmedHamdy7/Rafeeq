<?php

use App\Domains\Booking\Enums\PickupPointRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Models\Place;
use App\Domains\Group\Models\GroupMember;
use Illuminate\Support\Facades\Storage;

/**
 * "Could you pick me up here instead?" — Master Plan §889's custom pickup request,
 * with the driver's three answers and the passenger's one answer back.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');

    // `allows_custom_pickup` defaults to FALSE in the product, so a commute that
    // accepts proposals has to say so.
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['allowsCustomPickup' => true]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
    $this->paxToken = verifiedPassenger('01112223344');

    $this->requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
});

/**
 * Almost exactly on the straight line between Rehab and Smart Village, so the test
 * engine measures a detour of about nothing.
 */
function onTheWay(): array
{
    return ['lat' => 30.0654, 'lng' => 31.2314, 'label' => 'أمام صيدلية العبور'];
}

function proposePickup(string $token, string $seatRequestId, array $overrides = [])
{
    return test()->withToken($token)
        ->postJson("/api/v1/seat-requests/{$seatRequestId}/pickup-request", array_merge(onTheWay(), $overrides));
}

it('records a proposal with the detour measured by us', function () {
    $pickup = proposePickup($this->paxToken, $this->requestId)->assertStatus(201)->json('data');

    expect($pickup['status'])->toBe('PENDING')
        ->and($pickup['label'])->toBe('أمام صيدلية العبور')
        // Computed from the driver's published route, and small because the point is
        // on the way.
        ->and($pickup['addedMinutes'])->toBeLessThan(2.0)
        ->and($pickup['effectiveFrom'])->toBe('next_trip');
});

/**
 * 🔴 The load-bearing test for this feature. `added_minutes` decides whether the
 * proposal is inside the driver's `max_detour_minutes`, so a passenger who could
 * supply it would be deciding how far out of their way the driver goes.
 */
it('ignores a detour the requester tries to claim', function () {
    proposePickup($this->paxToken, $this->requestId, [
        'addedMinutes' => 0.1,
        'addedKm' => 0.01,
        // And the two fields that are the driver's answer, not the request.
        'status' => 'approved',
        'alternativePlaceId' => null,
    ])->assertStatus(201);

    $pickup = PickupPointRequest::sole();

    expect($pickup->status)->toBe(PickupPointRequestStatus::Pending)
        // Our measurement, not theirs. Anything but 0.1 proves the claim was dropped
        // rather than trusted.
        ->and((float) $pickup->added_minutes)->not->toBe(0.1);
});

it('refuses a point further off the route than the driver accepts', function () {
    // Sixty kilometres north of the origin: a two-hour detour on a fifty-kilometre
    // journey, against a stated limit of ten minutes.
    proposePickup($this->paxToken, $this->requestId, ['lat' => 30.60, 'lng' => 31.40])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PICKUP_DETOUR_TOO_LONG');

    expect(PickupPointRequest::count())->toBe(0);
});

it('refuses a proposal on a commute whose driver does not take them', function () {
    CommuteOffer::query()->whereKey($this->commuteId)->update(['allows_custom_pickup' => false]);

    proposePickup($this->paxToken, $this->requestId)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PICKUP_NOT_ON_COMMUTE');
});

it('refuses a second open proposal', function () {
    proposePickup($this->paxToken, $this->requestId)->assertStatus(201);

    proposePickup($this->paxToken, $this->requestId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'PICKUP_ALREADY_REQUESTED');
});

/**
 * 🔒 The proposed point is usually somebody's front door, and a proposal the driver
 * has not agreed to is not a relationship that has earned a home address.
 */
it('fuzzes the proposed point until it is approved', function () {
    $pending = proposePickup($this->paxToken, $this->requestId)->assertStatus(201)->json('data.proposedPoint');

    expect($pending['isExact'])->toBeFalse()
        ->and($pending['lat'])->toBe(round(30.0654, 3));

    $pickupId = PickupPointRequest::sole()->id;

    $approved = test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/approve")
        ->assertOk()->json('data.proposedPoint');

    // They are meeting there tomorrow morning, so the exact point is the point.
    expect($approved['isExact'])->toBeTrue()
        ->and($approved['lat'])->toBe(30.0654);
});

/**
 * An approval that only changed a status would leave everybody with a row saying
 * "yes" and a booking that still says to wait at the old gate.
 */
it('writes the agreed point onto the upcoming booking', function () {
    approveSeat($this->driverToken, $this->requestId);

    $member = GroupMember::query()->where('role', 'trial')->sole();

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/groups/{$member->commute_group_id}/pickup-request", onTheWay())
        ->assertStatus(201);

    $pickupId = PickupPointRequest::sole()->id;

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'APPROVED');

    $booking = Booking::sole();

    expect($booking->pickup_point)->not->toBeNull()
        ->and(round($booking->pickup_point->lat, 4))->toBe(30.0654);
});

it('lets an existing member propose against their membership, not a seat request', function () {
    approveSeat($this->driverToken, $this->requestId);

    $member = GroupMember::query()->where('role', 'trial')->sole();

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/groups/{$member->commute_group_id}/pickup-request", onTheWay())
        ->assertStatus(201)
        ->assertJsonPath('data.groupMemberId', $member->id)
        // ERD §23.1 added `group_member_id` for exactly this: the group screen's
        // "suggest a different meeting point".
        ->assertJsonPath('data.seatRequestId', null);
});

it('lets the driver counter with a place, which moves nothing until accepted', function () {
    proposePickup($this->paxToken, $this->requestId)->assertStatus(201);
    approveSeat($this->driverToken, $this->requestId);

    $pickupId = PickupPointRequest::sole()->id;
    $place = Place::factory()->create(['lat' => 30.0660, 'lng' => 31.2400]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/suggest-alternative", ['placeId' => $place->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'SUGGESTED_ALTERNATIVE')
        ->assertJsonPath('data.alternativePlaceId', $place->id);

    // Nothing has moved: relocating somebody's morning without asking them is the
    // mirror image of what this whole flow exists to prevent.
    expect(Booking::sole()->pickup_point)->toBeNull();

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/pickup-requests/{$pickupId}/accept-alternative")
        ->assertOk()
        ->assertJsonPath('data.status', 'APPROVED');

    // And now it is the PLACE's point, not the one originally proposed — that was
    // the whole content of the counter-offer.
    $booking = Booking::sole()->refresh();

    expect($booking->pickup_place_id)->toBe($place->id)
        ->and(round($booking->pickup_point->lat, 4))->toBe(30.066);
});

it('lets the driver refuse, and closes the conversation', function () {
    proposePickup($this->paxToken, $this->requestId)->assertStatus(201);

    $pickupId = PickupPointRequest::sole()->id;

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/reject")
        ->assertOk()
        ->assertJsonPath('data.status', 'REJECTED');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'PICKUP_REQUEST_NOT_PENDING');
});

/**
 * 🔒 A pickup request names a place a real person stands at every morning.
 */
it('hides a proposal from every driver but the one it was addressed to', function () {
    proposePickup($this->paxToken, $this->requestId)->assertStatus(201);

    $pickupId = PickupPointRequest::sole()->id;

    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223339999', devicePublicId: 'driver-2', seed: 2);

    // 404, not 403: a 403 would confirm the id exists.
    test()->withToken($otherDriver)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/approve")
        ->assertStatus(404);

    test()->withToken($otherDriver)->getJson('/api/v1/driver/pickup-requests')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // The driver it was addressed to does see it, through the seat-request key.
    test()->withToken($this->driverToken)->getJson('/api/v1/driver/pickup-requests')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('refuses a proposal against somebody else seat request', function () {
    $stranger = verifiedPassenger('01223334455', device: 'pax-3');

    proposePickup($stranger, $this->requestId)->assertStatus(404);

    expect(PickupPointRequest::count())->toBe(0);
});

it('refuses a proposal once the seat request has been answered', function () {
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$this->requestId}/reject")->assertOk();

    proposePickup($this->paxToken, $this->requestId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_NOT_PENDING');
});
