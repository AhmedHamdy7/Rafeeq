<?php

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Actions\ProposeCommuteChangeAction;
use App\Domains\Commute\Enums\ChangeProposalStatus;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Notification\Models\Notification;

/**
 * Changing a commute people have already booked, with their agreement (Feature 03 v5 §6).
 *
 * > *"Route/time/pickup changes affecting confirmed passengers require unanimous explicit approval,
 * > revisions reset approvals; no response means no change."*
 *
 * 🔴 **This ADDS a capability rather than restricting one.** Until now a published commute's
 * departure could not be changed at all — `CommuteState::assertEditable()` allows it only on a
 * draft, because people book specific days on the strength of it. v5's unanimous-approval rule
 * presupposes the edit is possible, so the proposal flow is what makes it possible, and only with
 * every affected passenger's explicit yes.
 *
 * The file closes with v5's own **Scenario D**, written out as it is stated there.
 */
beforeEach(function () {
    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['seatsTotal' => 3]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
});

/**
 * Somebody confirmed on the day, and her token.
 *
 * @return array{token: string, bookingId: string}
 */
function riderFor(string $driverToken, string $commuteId, string $tripId, string $phone, string $device): array
{
    $token = verifiedPassenger($phone, device: $device);

    $requestId = requestSeat($token, $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data.id');

    return ['token' => $token, 'bookingId' => approveSeat($driverToken, $requestId)];
}

function proposeChange(string $driverToken, string $commuteId, array $payload)
{
    return test()->flushHeaders()->withToken($driverToken)
        ->postJson("/api/v1/driver/commutes/{$commuteId}/change-proposals", $payload);
}

function answerProposal(string $token, string $proposalId, string $response)
{
    return test()->flushHeaders()->withToken($token)
        ->postJson("/api/v1/change-proposals/{$proposalId}/respond", ['response' => $response]);
}

/**
 * 🔴 The ordinary edit is still refused. The proposal path is the exception, not a relaxation.
 */
it('still refuses a direct edit to a published commute’s schedule', function () {
    saveSchedule($this->driverToken, $this->commuteId, ['departureTime' => '06:30:00'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_NOT_EDITABLE');
});

it('records what is being proposed against what it is now', function () {
    riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    $proposal = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data');

    expect($proposal['status'])->toBe('PENDING')
        ->and($proposal['version'])->toBe(1)
        ->and($proposal['changes']['departureTime'])->toBe('07:30:00')
        // 🔴 The comparison is captured now, so what she agreed to cannot drift under her.
        ->and($proposal['previous']['departureTime'])->toBe('07:05:00')
        // It stops being answerable when the car goes.
        ->and($proposal['expiresAt'])->toBe(ScheduledTrip::findOrFail($this->tripId)->departure_at->toIso8601String());

    // The affected passenger is asked, with wording that says nothing has changed yet.
    $told = Notification::query()->where('type', 'change_proposal_received')->get();

    expect($told)->toHaveCount(1)
        ->and($told->first()->body)->toContain('Nothing changes until');
});

/**
 * 🔴 v5: *"If no reply, nothing changes."*
 */
it('changes nothing while anybody has not answered', function () {
    riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    riderFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    $before = ScheduledTrip::findOrFail($this->tripId)->departure_local->format('H:i:s');

    // One accepts; the other says nothing.
    answerProposal(
        riderFor($this->driverToken, $this->commuteId, $this->tripId, '01166778899', 'pax-3')['token'],
        $proposalId,
        'accepted',
    );

    expect(ChangeProposal::findOrFail($proposalId)->status)->toBe(ChangeProposalStatus::Pending)
        ->and(ScheduledTrip::findOrFail($this->tripId)->departure_local->format('H:i:s'))->toBe($before);
});

/**
 * 🔴 The last yes applies it there and then. Everybody has agreed; leaving the change unapplied
 * until the driver taps something would mean a commute whose people all consented to 07:30 still
 * departing at 07:05 — and if she never taps, they arrive at the time they agreed to leave behind.
 */
it('applies the change on the answer that makes it unanimous', function () {
    $first = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    $second = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    answerProposal($first['token'], $proposalId, 'accepted')->assertOk();

    expect(ChangeProposal::findOrFail($proposalId)->status)->toBe(ChangeProposalStatus::Pending);

    answerProposal($second['token'], $proposalId, 'accepted')
        ->assertOk()
        ->assertJsonPath('data.status', 'APPLIED');

    $trip = ScheduledTrip::findOrFail($this->tripId);

    expect($trip->departure_local->format('H:i:s'))->toBe('07:30:00')
        // The UTC instant moved with it, not just the wall clock — a day whose two columns
        // disagreed would start at one time and be scored against another.
        ->and($trip->departure_at->format('H:i'))->not->toBe('07:05');
});

/**
 * 🔴 v5: *"Change proposal partial acceptance never applies"* — so one refusal ends it, rather than
 * leaving everybody else waiting on an answer that cannot change the outcome.
 */
it('ends the proposal when somebody refuses', function () {
    $first = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    $second = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    answerProposal($first['token'], $proposalId, 'accepted')->assertOk();
    answerProposal($second['token'], $proposalId, 'rejected')
        ->assertOk()
        ->assertJsonPath('data.status', 'REJECTED');

    expect(ScheduledTrip::findOrFail($this->tripId)->departure_local->format('H:i:s'))->toBe('07:05:00')
        ->and(Notification::query()->where('type', 'change_proposal_rejected')->count())->toBe(1);
});

/**
 * 🔴 v5's hardest rule here: *"New affected passengers confirmed while proposal pending must also
 * approve."* Nothing hooks into the booking path — "affected" is recomputed on every check, so a
 * seat confirmed after the proposal went out simply has no answer yet.
 */
it('is not unanimous once somebody new is confirmed on the day', function () {
    $first = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    // A second seat is confirmed while the proposal is out, and she has not been asked yet.
    riderFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    answerProposal($first['token'], $proposalId, 'accepted')->assertOk();

    // 🔴 Still pending: the newcomer's silence counts, with nothing having hooked into her booking.
    expect(ChangeProposal::findOrFail($proposalId)->status)->toBe(ChangeProposalStatus::Pending)
        ->and(ScheduledTrip::findOrFail($this->tripId)->departure_local->format('H:i:s'))->toBe('07:05:00');
});

/**
 * 🔴 v5: *"Editing resets all approvals."* Somebody who agreed to 07:45 has not agreed to 07:30.
 */
it('resets every approval when the driver revises it', function () {
    $first = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    $second = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    answerProposal($first['token'], $proposalId, 'accepted')->assertOk();

    test()->flushHeaders()->withToken($this->driverToken)
        ->patchJson("/api/v1/driver/change-proposals/{$proposalId}", [
            'scheduledTripId' => $this->tripId,
            'scope' => 'only_this_occurrence',
            'departureTime' => '07:45:00',
        ])
        ->assertOk()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.status', 'PENDING');

    // Her earlier yes no longer counts, so the second passenger accepting does not apply it.
    answerProposal($second['token'], $proposalId, 'accepted')->assertOk();

    expect(ChangeProposal::findOrFail($proposalId)->status)->toBe(ChangeProposalStatus::Pending)
        ->and(ScheduledTrip::findOrFail($this->tripId)->departure_local->format('H:i:s'))->toBe('07:05:00');

    // 🔴 And the old answer is kept, not deleted: the driver's screen can still show she had agreed.
    expect(ChangeProposal::findOrFail($proposalId)->approvals()->where('proposal_version', 1)->count())->toBe(1);

    // Everybody is told the proposal moved, because somebody who said yes has to know it no
    // longer stands.
    expect(Notification::query()->where('type', 'change_proposal_revised')->count())->toBe(2);
});

/**
 * v5: *"Passenger can withdraw acceptance until applied."*
 */
it('lets a passenger take her acceptance back before it applies', function () {
    $first = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    $second = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    answerProposal($first['token'], $proposalId, 'accepted')->assertOk();
    answerProposal($first['token'], $proposalId, 'withdrawn')
        ->assertOk()
        ->assertJsonPath('data.myResponse', 'WITHDRAWN');

    answerProposal($second['token'], $proposalId, 'accepted')->assertOk();

    // Not applied: her withdrawal is not a yes.
    expect(ChangeProposal::findOrFail($proposalId)->status)->toBe(ChangeProposalStatus::Pending);
});

/**
 * The driver's pending view, with v5's four per-passenger states — and `AWAITING_RESPONSE` named
 * rather than left as a gap.
 */
it('shows the driver who has answered and who has not', function () {
    $first = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    riderFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    answerProposal($first['token'], $proposalId, 'accepted')->assertOk();

    $view = test()->flushHeaders()->withToken($this->driverToken)
        ->getJson('/api/v1/driver/change-proposals')
        ->assertOk()->json('data.0');

    expect($view['affectedCount'])->toBe(2)
        ->and($view['acceptedCount'])->toBe(1)
        ->and(array_column($view['passengers'], 'response'))
        ->toContain('ACCEPTED')
        ->toContain('AWAITING_RESPONSE')
        // 🔒 A public first name and her answer, nothing else about her.
        ->and($view['passengers'][0])->not->toHaveKey('phone');
});

/**
 * 🔒 A passenger is being asked about her own morning. Who else is in the car, and whether they
 * agreed, is not a fact about her journey — and publishing it would turn a schedule change into
 * social pressure from people she may not have met.
 */
it('does not tell a passenger how the others answered', function () {
    $first = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    riderFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201);

    $mine = test()->flushHeaders()->withToken($first['token'])
        ->getJson('/api/v1/change-proposals')
        ->assertOk()->json('data.0');

    expect($mine['changes']['departureTime'])->toBe('07:30:00')
        ->and($mine['previous']['departureTime'])->toBe('07:05:00')
        ->and($mine)->not->toHaveKey('passengers')
        ->and($mine)->not->toHaveKey('acceptedCount')
        ->and($mine)->not->toHaveKey('affectedCount');
});

/**
 * 🔒 Only somebody with a confirmed booking on an affected day may answer. Without this, anybody
 * could answer for anybody and a change would apply on strangers' consent.
 */
it('refuses an answer from somebody with no seat on the day', function () {
    riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    $stranger = verifiedPassenger('01199887766', device: 'pax-9');

    answerProposal($stranger, $proposalId, 'accepted')->assertStatus(404);

    expect(ScheduledTrip::findOrFail($this->tripId)->departure_local->format('H:i:s'))->toBe('07:05:00');
});

/**
 * 🔴 Nobody to ask means refused, not applied. A change through this path with no approvals would
 * be a route around the whole mechanism the moment the last passenger cancelled.
 */
it('refuses a proposal that affects nobody', function () {
    proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CHANGE_PROPOSAL_NOBODY_AFFECTED');
});

/**
 * Two live proposals would ask the same passenger about two different mornings with no way to say
 * which won.
 */
it('refuses a second proposal while one is still out', function () {
    riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201);

    proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:45:00',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CHANGE_PROPOSAL_ALREADY_OPEN');
});

/**
 * 🔴 A pickup change cannot be scoped to one morning: the commute has one advertised route across
 * its weekdays, so moving the stops for Tuesday alone would change every other morning as a side
 * effect.
 */
it('refuses a pickup change scoped to a single day', function () {
    riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'pickupPoints' => [['lat' => 30.0624, 'lng' => 31.3614, 'address' => 'Ring Road']],
    ])->assertStatus(422);
});

/**
 * 🔴 An all-future change rewrites the weekday row as well as the days already generated. Updating
 * only the existing days would have the change revert itself the moment the horizon rolled forward.
 */
it('rewrites the weekday template on an all-future change', function () {
    $rider = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    $trip = ScheduledTrip::findOrFail($this->tripId);

    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'all_future_on_this_weekday',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    answerProposal($rider['token'], $proposalId, 'accepted')->assertOk();

    $bit = 1 << (((int) $trip->departure_local->dayOfWeek + 1) % 7);

    expect(CommuteWeekdaySchedule::query()
        ->where('commute_offer_id', $this->commuteId)
        ->where('weekday_bit', $bit)
        ->value('departure_time'))->toBe('07:30:00');
});

/**
 * 🔴 v5: an Edit Entire Commute clears the waiting list after a successful application — *"even
 * those apparently still compatible"*. We cannot tell from a 07:05 application whether 07:30 still
 * works for somebody; keeping the ones that look fine would be deciding that on her behalf.
 */
it('clears the waiting list after an all-future change is applied', function () {
    $rider = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    $waiterToken = verifiedPassenger('01155667788', device: 'pax-2');
    $waiterRequest = requestSeat($waiterToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$waiterRequest}/waitlist")->assertOk();

    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'all_future_on_this_weekday',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    answerProposal($rider['token'], $proposalId, 'accepted')->assertOk();

    expect(SeatRequest::findOrFail($waiterRequest)->status)->toBe(SeatRequestStatus::TermsChanged)
        ->and(SeatRequest::findOrFail($waiterRequest)->waitlist_position)->toBeNull()
        // Told, with wording that does not read as a refusal.
        ->and(Notification::query()->where('type', 'waitlist_cleared_by_change')->count())->toBe(1);
});

/**
 * 🔴 And NOT cleared when the proposal does not apply. v5: *"If proposal rejected/unapplied, do not
 * clear."*
 */
it('leaves the waiting list alone when the proposal is refused', function () {
    $rider = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    $waiterToken = verifiedPassenger('01155667788', device: 'pax-2');
    $waiterRequest = requestSeat($waiterToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$waiterRequest}/waitlist")->assertOk();

    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'all_future_on_this_weekday',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    answerProposal($rider['token'], $proposalId, 'rejected')->assertOk();

    expect(SeatRequest::findOrFail($waiterRequest)->status)->toBe(SeatRequestStatus::Waitlisted);
});

/**
 * v5: *"Proposal expires at the original locked departure time."* A proposal that outlived its
 * morning would let a late yes rewrite a journey that already happened.
 */
it('expires a proposal once its day has gone', function () {
    riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    ChangeProposal::query()->whereKey($proposalId)->update(['expires_at' => now()->subMinute()]);

    expect(ProposeCommuteChangeAction::expireOverdue())->toBe(1)
        ->and(ChangeProposal::findOrFail($proposalId)->status)->toBe(ChangeProposalStatus::Expired);
});

/**
 * 🔴 **v5's Scenario D, written out as it is stated:** *"Driver proposes time + pickup change → one
 * passenger accepts, another silent → revision resets approvals → apply only on unanimous
 * approval."*
 */
it('runs v5’s scenario D end to end', function () {
    $mariam = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    $salma = riderFor($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    // Time AND pickup, combined in one request.
    $proposalId = proposeChange($this->driverToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'scope' => 'all_future_on_this_weekday',
        'departureTime' => '07:30:00',
        'pickupPoints' => [['lat' => 30.0624, 'lng' => 31.3614, 'address' => 'Gate 4']],
    ])->assertStatus(201)->json('data.id');

    // Mariam accepts; Salma says nothing → not applied.
    answerProposal($mariam['token'], $proposalId, 'accepted')->assertOk();

    expect(ChangeProposal::findOrFail($proposalId)->status)->toBe(ChangeProposalStatus::Pending)
        ->and(ScheduledTrip::findOrFail($this->tripId)->departure_local->format('H:i:s'))->toBe('07:05:00');

    // The driver revises to 07:45 → both approvals reset.
    test()->flushHeaders()->withToken($this->driverToken)
        ->patchJson("/api/v1/driver/change-proposals/{$proposalId}", [
            'scheduledTripId' => $this->tripId,
            'scope' => 'all_future_on_this_weekday',
            'departureTime' => '07:45:00',
            'pickupPoints' => [['lat' => 30.0624, 'lng' => 31.3614, 'address' => 'Gate 4']],
        ])->assertOk()->assertJsonPath('data.version', 2);

    // Mariam's earlier yes no longer counts, so Salma alone does not carry it.
    answerProposal($salma['token'], $proposalId, 'accepted')->assertOk();

    expect(ChangeProposal::findOrFail($proposalId)->status)->toBe(ChangeProposalStatus::Pending);

    // Both on the new version → applied.
    answerProposal($mariam['token'], $proposalId, 'accepted')
        ->assertOk()
        ->assertJsonPath('data.status', 'APPLIED');

    expect(ScheduledTrip::findOrFail($this->tripId)->departure_local->format('H:i:s'))->toBe('07:45:00');
});
