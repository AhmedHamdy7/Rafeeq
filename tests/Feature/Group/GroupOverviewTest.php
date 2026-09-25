<?php

use App\Domains\Commute\Models\CommuteRule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Models\CommuteGroup;
use Illuminate\Support\Facades\Storage;

/**
 * The group screen: overview, rules and members (Master Plan §889 — "Group:
 * overview، members (بخصوصية)").
 *
 * 🔒 The privacy half is the part that matters. A member list is a set of real
 * people's first names, trust levels and the days they reliably travel — which is
 * also when they are not at home. Nothing outside the group may read it, and nobody
 * inside it gets a full name or a phone number.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    // One trial rider, which is what creates the group.
    $this->paxToken = verifiedPassenger('01112223344', device: 'pax-1');

    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    approveSeat($this->driverToken, $requestId);

    $this->groupId = CommuteGroup::sole()->id;
});

it('lists the groups a passenger travels with', function () {
    test()->withToken($this->paxToken)->getJson('/api/v1/groups')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->groupId)
        // Named after the journey, not "Group 47".
        ->assertJsonPath('data.0.name', 'Rehab Gate 2 → Smart Village B6');
});

it('includes the driver own group in their list', function () {
    test()->withToken($this->driverToken)->getJson('/api/v1/groups')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('describes the arrangement, not a single trip', function () {
    CommuteRule::factory()->create([
        'commute_offer_id' => $this->commuteId,
        'rule_key' => 'quiet',
        'rule_value' => true,
    ]);

    $group = test()->withToken($this->paxToken)->getJson("/api/v1/groups/{$this->groupId}")
        ->assertOk()->json('data');

    expect($group['status'])->toBe('ACTIVE')
        ->and($group['noticePeriodDays'])->toBe(7)
        ->and($group['minCommitmentDaysPerWeek'])->toBe(3)
        ->and($group['memberCount'])->toBe(2)
        // The rules somebody agreed to when they joined, looked up months later.
        ->and($group['commute']['rules'])->toBe(['quiet' => true])
        // Two of three seats left on the next day, computed rather than read from a
        // denormalised counter nobody owns.
        ->and($group['seatsOpenNextTrip'])->toBe(2);
});

/**
 * Zero rides is "no rides yet", not "never on time". A client showing it as a score
 * would be libelling a driver who has not driven.
 */
it('reports a new group as having no history rather than a bad one', function () {
    $group = test()->withToken($this->paxToken)->getJson("/api/v1/groups/{$this->groupId}")
        ->assertOk()->json('data');

    /*
     * Compared as a number, not identically to 0.0, and the reason is worth knowing:
     * PHP's `json_encode` writes the float 0.0 as `0`, so every whole-numbered
     * percentage in this API arrives on the wire without a decimal point. A Dart
     * client that reads it as `double` rather than `num` throws on exactly this
     * value — which is why the OpenAPI description says so.
     */
    expect((float) $group['onTimePct'])->toBe(0.0)
        ->and($group['ridesTogetherCount'])->toBe(0);
});

/**
 * 🔒 The privacy boundary, asserted as an absence rather than a presence: it is the
 * fields that are NOT there that make this safe.
 */
it('shows members as public first names and trust levels only', function () {
    $members = test()->withToken($this->paxToken)->getJson("/api/v1/groups/{$this->groupId}/members")
        ->assertOk()->json('data');

    expect($members)->toHaveCount(2)
        // The driver first: they are the one person always there.
        ->and($members[0]['role'])->toBe('driver')
        ->and($members[1]['role'])->toBe('trial');

    foreach ($members as $member) {
        expect($member['person'])->toHaveKeys(['publicFirstName', 'trustLevel'])
            // A commute group is not a reason to hand four strangers somebody's
            // phone number permanently.
            ->and($member['person'])->not->toHaveKey('phone')
            ->and($member['person'])->not->toHaveKey('phoneE164')
            ->and($member['person'])->not->toHaveKey('fullName')
            ->and($member['person'])->not->toHaveKey('email')
            // Gender is never exposed, anywhere.
            ->and($member['person'])->not->toHaveKey('gender');
    }
});

/**
 * 🔒 IDOR. A 404 rather than a 403, because a 403 would confirm the group exists.
 */
it('hides a group completely from anybody not in it', function () {
    $stranger = verifiedPassenger('01223334455', device: 'pax-2');

    foreach ([
        "/api/v1/groups/{$this->groupId}",
        "/api/v1/groups/{$this->groupId}/members",
        "/api/v1/groups/{$this->groupId}/attendance",
        "/api/v1/groups/{$this->groupId}/absences",
    ] as $route) {
        test()->withToken($stranger)->getJson($route)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    test()->withToken($stranger)->getJson('/api/v1/groups')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('requires a verified identity, like the rest of getting into a car', function () {
    $unverified = signIn(phone: '01555556666', devicePublicId: 'pax-unverified')['session']['accessToken'];

    completeBasicProfile($unverified);

    test()->withToken($unverified)->getJson('/api/v1/groups')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'VERIFICATION_REQUIRED');
});
