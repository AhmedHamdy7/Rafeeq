<?php

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Support\Facades\Storage;

/**
 * `Receive Requests During Ride` (Feature 03 v5 §8, §9 and its API table:
 * *"Default true; per occurrence"*).
 *
 * ⚠️ **Nothing acts on this yet, and these tests say so rather than pretending otherwise.** The
 * mid-journey seat request it governs is not built: v5 leaves its hardest part open — *"on active
 * trip, reachability depends on actual route progress and predicted ETA"* — and building the flow
 * would mean inventing that model. v5's own out-of-scope note asks for the opposite: *"implement
 * durable states/hooks, not speculative behavior."*
 *
 * 🔴 So what is tested here is that her ANSWER is stored, scoped and reachable — not that anything
 * obeys it. The default is yes, so every driver's stored state already matches today's behaviour;
 * the only answer that could ever be broken is "no", and a "no" first collected on the day the
 * feature ships would mean the first mid-journey request reaching a driver who had already refused.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();
});

/**
 * 🔴 v5 states the default outright, and it is the right way round: the feature exists to fill an
 * empty seat on a journey already happening, so defaulting to "no" would turn it off for everybody
 * who never found the switch.
 */
it('defaults to yes on every day', function () {
    expect(ScheduledTrip::query()->where('commute_offer_id', $this->commuteId)->count())
        ->toBeGreaterThan(1)
        ->and(ScheduledTrip::query()
            ->where('commute_offer_id', $this->commuteId)
            ->where('receives_requests_during_ride', false)
            ->count())->toBe(0);
});

it('lets her turn it off and on again for one morning', function () {
    test()->withToken($this->driverToken)
        ->patchJson("/api/v1/trips/{$this->trip->id}/receive-requests", ['receiveRequests' => false])
        ->assertOk()
        ->assertJsonPath('data.tripId', $this->trip->id)
        ->assertJsonPath('data.receivesRequestsDuringRide', false);

    expect(ScheduledTrip::query()->whereKey($this->trip->id)->sole()->receives_requests_during_ride)
        ->toBeFalse();

    test()->flushHeaders()->withToken($this->driverToken)
        ->patchJson("/api/v1/trips/{$this->trip->id}/receive-requests", ['receiveRequests' => true])
        ->assertOk()
        ->assertJsonPath('data.receivesRequestsDuringRide', true);
});

/**
 * 🔴 Per occurrence, as v5 specifies. Whether she can take another passenger depends on THIS
 * morning — who is aboard, how late she is, where she has got to — so a commute-wide switch would
 * make her answer for a quiet Thursday on the strength of a bad Tuesday.
 */
it('changes one morning and leaves the rest of the week alone', function () {
    test()->withToken($this->driverToken)
        ->patchJson("/api/v1/trips/{$this->trip->id}/receive-requests", ['receiveRequests' => false])
        ->assertOk();

    $others = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->whereKeyNot($this->trip->getKey())
        ->get();

    expect($others)->not->toBeEmpty()
        ->and($others->pluck('receives_requests_during_ride')->unique()->all())->toBe([true]);
});

/**
 * 🔒 Published on the driver-only navigation route, which is where v5 §9 puts the toggle — "the
 * toggle accessible without interrupting navigation" — so the screen that owns the switch can draw
 * it without a second call.
 */
it('is readable on the navigation route she drives from', function () {
    test()->withToken($this->driverToken)
        ->patchJson("/api/v1/trips/{$this->trip->id}/receive-requests", ['receiveRequests' => false])
        ->assertOk();

    test()->flushHeaders()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/commute-occurrences/{$this->trip->id}/navigation-route")
        ->assertOk()
        ->assertJsonPath('data.receivesRequestsDuringRide', false);
});

/**
 * 🔒 And NOT on the shape a passenger reads. Her booking embeds the day, and whether the driver is
 * willing to be interrupted mid-journey is an operational setting of hers that no passenger screen
 * has any use for.
 */
it('is invisible to the passenger who booked the day', function () {
    $paxToken = verifiedPassenger('01223339999', device: 'pax-2');

    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    $bookingId = approveSeat($this->driverToken, $requestId);

    $booking = test()->flushHeaders()->withToken($paxToken)
        ->getJson("/api/v1/bookings/{$bookingId}")
        ->assertOk()
        ->json('data');

    expect($booking['trip'])->not->toBeNull()
        ->and(array_keys($booking['trip']))->not->toContain('receivesRequestsDuringRide')
        ->and(json_encode($booking))->not->toContain('receivesRequestsDuringRide');
});

/**
 * 🔒 Hers alone, behind a 404 — a 403 would confirm the day exists on somebody's commute.
 */
it('refuses somebody else’s morning', function () {
    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223338888', devicePublicId: 'driver-2', seed: 2);

    test()->flushHeaders()->withToken($otherDriver)
        ->patchJson("/api/v1/trips/{$this->trip->id}/receive-requests", ['receiveRequests' => false])
        ->assertStatus(404);

    $paxToken = verifiedPassenger('01223339999', device: 'pax-2');

    test()->flushHeaders()->withToken($paxToken)
        ->patchJson("/api/v1/trips/{$this->trip->id}/receive-requests", ['receiveRequests' => false])
        ->assertStatus(404);

    expect(ScheduledTrip::query()->whereKey($this->trip->id)->sole()->receives_requests_during_ride)
        ->toBeTrue();
});

/**
 * A toggle on a morning that is over changes nothing, and answering OK would tell a client it had
 * been applied.
 */
it('refuses a day that has already ended', function () {
    ScheduledTrip::query()->whereKey($this->trip->id)->update(['status' => 'completed']);

    test()->withToken($this->driverToken)
        ->patchJson("/api/v1/trips/{$this->trip->id}/receive-requests", ['receiveRequests' => false])
        ->assertStatus(409)
        ->assertJsonPath('error.fields.tripStatus.0', 'COMPLETED');
});

it('requires an explicit answer', function () {
    test()->withToken($this->driverToken)
        ->patchJson("/api/v1/trips/{$this->trip->id}/receive-requests", [])
        ->assertStatus(422);
});
