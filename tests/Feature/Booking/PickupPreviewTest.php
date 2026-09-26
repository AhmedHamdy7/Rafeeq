<?php

use App\Domains\Booking\Enums\PickupPointRequestStatus;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * The detour a passenger is shown WHILE composing a seat request (screen 13's `DETOUR +4′`),
 * and the run total the driver reads when deciding (screen 29's "+8 min total for the run").
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['allowsCustomPickup' => true]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
    $this->paxToken = verifiedPassenger('01112223344');
});

function previewPickup(string $token, string $commuteId, array $point)
{
    return test()->withToken($token)->postJson("/api/v1/commutes/{$commuteId}/pickup-preview", $point);
}

it('answers what a point would cost before anything is created', function () {
    $preview = previewPickup($this->paxToken, $this->commuteId, onTheWay())
        ->assertOk()->json('data');

    expect($preview['addedMinutes'])->toBeLessThan(2.0)
        ->and($preview['maxDetourMinutes'])->toBe(10)
        // Stated rather than left for the client to compare two numbers and get the
        // boundary case wrong.
        ->and($preview['withinLimit'])->toBeTrue()
        ->and($preview)->toHaveKey('runTotalMinutes');

    // And nothing was created: this is a question, not a request.
    expect(PickupPointRequest::count())->toBe(0);
});

it('says plainly when a point is past the limit', function () {
    // Sixty kilometres north of the origin.
    $preview = previewPickup($this->paxToken, $this->commuteId, ['lat' => 30.60, 'lng' => 31.40])
        ->assertOk()->json('data');

    /*
     * 200 with `withinLimit: false`, not a 422. The passenger is asking "would this
     * work?", and the honest answer to a bad point is "no, here is why" — not an error
     * that makes the screen look broken while they are dragging a pin around.
     */
    expect($preview['withinLimit'])->toBeFalse()
        ->and($preview['addedMinutes'])->toBeGreaterThan(10);
});

/**
 * 🔒 The preview must not become a way to map a driver's route. A man cannot measure
 * against a women-only commute any more than he can book it.
 */
it('refuses a commute the caller is not eligible for', function () {
    $manToken = verifiedPassenger('01223334455', gender: 'man', device: 'pax-man');

    previewPickup($manToken, $this->commuteId, onTheWay())->assertStatus(404);
});

it('refuses a commute whose driver does not take custom pickups', function () {
    CommuteOffer::query()->whereKey($this->commuteId)->update(['allows_custom_pickup' => false]);

    previewPickup($this->paxToken, $this->commuteId, onTheWay())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PICKUP_NOT_ON_COMMUTE');
});

it('refuses coordinates that are not coordinates', function () {
    previewPickup($this->paxToken, $this->commuteId, ['lat' => 'north', 'lng' => 31.2])
        ->assertStatus(422);

    previewPickup($this->paxToken, $this->commuteId, ['lat' => 200, 'lng' => 31.2])
        ->assertStatus(422);
});

/**
 * 🔴 The hole the run total closes.
 *
 * The limit used to be checked against ONE request's detour, so five separate
 * three-minute pickups each passed a ten-minute check and left the driver with a
 * fifteen-minute detour they never agreed to — approved one honest "yes" at a time.
 */
it('counts the pickups already approved against the driver limit', function () {
    $offer = CommuteOffer::query()->whereKey($this->commuteId)->sole();

    // Eight minutes of custom pickups already agreed to, on a ten-minute limit.
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $existing = new PickupPointRequest;

    $existing->fill([
        'seat_request_id' => $requestId,
        'requested_by_user_id' => User::query()
            ->where('phone_e164', '+201112223344')->sole()->id,
        'proposed_point' => ['lat' => 30.0654, 'lng' => 31.2314],
    ]);

    $existing->added_minutes = 8.0;
    $existing->added_km = 3.0;
    $existing->status = PickupPointRequestStatus::Approved->value;
    $existing->save();

    // A point that costs almost nothing on its own is now refused, because the RUN is
    // already at eight of the driver's ten minutes.
    $preview = previewPickup($this->paxToken, $this->commuteId, onTheWay())
        ->assertOk()->json('data');

    expect($preview['runTotalMinutes'])->toBeGreaterThanOrEqual(8.0)
        ->and($preview['addedMinutes'])->toBeLessThan(2.0);

    // And the second one alone would have looked fine under the old per-request check.
    expect($preview['runTotalMinutes'])->toBeGreaterThan($preview['addedMinutes']);
});

it('reports the run total as zero on a commute with no detours yet', function () {
    // The reference commute has no pickups of its own and nothing approved, so its route
    // is a straight run and the total detour is nothing.
    $preview = previewPickup($this->paxToken, $this->commuteId, onTheWay())
        ->assertOk()->json('data');

    expect((float) $preview['runTotalMinutes'])->toBeLessThan(2.0);
});

it('needs a verified identity, like requesting a seat does', function () {
    $unverified = signIn(phone: '01555556666', devicePublicId: 'pax-unverified')['session']['accessToken'];

    completeBasicProfile($unverified);

    previewPickup($unverified, $this->commuteId, onTheWay())
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'VERIFICATION_REQUIRED');
});
