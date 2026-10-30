<?php

use App\Domains\Commute\Enums\ChangeProposalStatus;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Storage;

/**
 * Changing a departure AFTER the sixteen-hour lock (Feature 03 v5 §95).
 *
 * > *"Post-lock driver-proposed earlier/later departure requires explicit unanimous approval from
 * > all affected confirmed passengers. New affected passengers confirmed while proposal pending
 * > must also approve. Until unanimously approved, original schedule remains authoritative.
 * > Proposal expires at the original locked departure time. Chat/calls are not consent."*
 *
 * 🔴 **This file pins behaviour rather than adding it, and that is the point of it existing.** The
 * progress notes carried this item as "not built — presupposes an editing capability that does not
 * exist", which was true when it was written and stopped being true the day the unanimous
 * change-proposal flow shipped. Nothing in the lock ever blocked *proposing*: the lock guards new
 * bookings and pickup-point changes, and a published commute's schedule was never directly
 * editable at all, so the proposal was always the only path and it was never closed inside the
 * window.
 *
 * So the risk here is not a missing feature. It is that every clause of v5 §95 is satisfied by
 * accident, by three mechanisms that were each built for their own reason, and that a later edit to
 * any of them would break a rule nobody had written down. These tests write it down.
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
        requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])
            ->assertStatus(201)->json('data.id'),
    );

    // 🔴 Inside the lock: the morning leaves in eight hours.
    runLeavingIn($this->trip->id, 8 * 60);
});

function proposeDeparture(string $driverToken, string $commuteId, string $tripId, string $time)
{
    return test()->flushHeaders()->withToken($driverToken)
        ->postJson("/api/v1/driver/commutes/{$commuteId}/change-proposals", [
            'scheduledTripId' => $tripId,
            'scope' => 'only_this_occurrence',
            'departureTime' => $time,
        ]);
}

/**
 * 🔴 v5 allows the proposal post-lock. It is the APPLYING that needs everybody's yes — and a driver
 * who has realised at midnight that she must leave twenty minutes earlier has to be able to ask.
 */
it('lets her propose a later departure inside the locked window', function () {
    proposeDeparture($this->driverToken, $this->commuteId, $this->trip->id, '07:25:00')
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'PENDING');

    expect(ChangeProposal::query()->sole()->status)->toBe(ChangeProposalStatus::Pending);
});

/**
 * 🔴 *"Until unanimously approved, original schedule remains authoritative."* The morning does not
 * move because she asked.
 */
it('leaves the morning exactly where it was until she answers', function () {
    $before = ScheduledTrip::query()->whereKey($this->trip->id)->sole();

    proposeDeparture($this->driverToken, $this->commuteId, $this->trip->id, '07:25:00')
        ->assertStatus(201);

    $after = ScheduledTrip::query()->whereKey($this->trip->id)->sole();

    expect($after->departure_at->toIso8601String())->toBe($before->departure_at->toIso8601String())
        ->and($after->departure_local->toDateTimeString())->toBe($before->departure_local->toDateTimeString());
});

/**
 * And it moves on her yes, inside the locked window as much as outside it — the lock is about who
 * must agree, not about whether agreement can work.
 */
it('moves the morning once the only affected passenger agrees', function () {
    $proposalId = proposeDeparture($this->driverToken, $this->commuteId, $this->trip->id, '07:25:00')
        ->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/change-proposals/{$proposalId}/respond", ['response' => 'accepted'])
        ->assertOk();

    expect(ChangeProposal::query()->whereKey($proposalId)->sole()->status)
        ->toBe(ChangeProposalStatus::Applied)
        ->and(ScheduledTrip::query()->whereKey($this->trip->id)->sole()->departure_local->format('H:i'))
        ->toBe('07:25');
});

/**
 * 🔴 *"Proposal expires at the original locked departure time."* A proposal nobody answered dies
 * with the morning it was about — it must never apply to a run that has already left, or sit open
 * against a journey that happened on the old time.
 */
it('expires at the departure it was asking to move', function () {
    $proposalId = proposeDeparture($this->driverToken, $this->commuteId, $this->trip->id, '07:25:00')
        ->assertStatus(201)->json('data.id');

    $proposal = ChangeProposal::query()->whereKey($proposalId)->sole();

    // The expiry is the ORIGINAL departure, not a window of its own.
    expect($proposal->expires_at->toIso8601String())
        ->toBe(ScheduledTrip::query()->whereKey($this->trip->id)->sole()->departure_at->toIso8601String());
});

/**
 * ⚠️ And a direct edit is still refused, locked window or not. v5 gives one path through, and the
 * reason the lock reads as "who must agree" rather than "nothing may change" is that this door was
 * never open in the first place.
 */
it('still refuses a direct edit to the schedule', function () {
    test()->withToken($this->driverToken)
        ->putJson("/api/v1/commutes/{$this->commuteId}/schedule", [
            'daysMask' => DaysMask::weekdaysSunToThu()->value,
            'departureTime' => '07:25:00',
            'startDate' => now()->addDay()->toDateString(),
            'endDate' => now()->addMonths(2)->toDateString(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_NOT_EDITABLE');
});

/**
 * 🔴 *"New affected passengers confirmed while proposal pending must also approve."* Somebody seated
 * inside the locked window, after the proposal went out, is affected by it — and unanimity that
 * counted only the people who happened to be aboard when the driver asked would apply a change to
 * somebody who never saw it.
 */
it('needs every seated passenger inside the window, not just the first to answer', function () {
    /*
     * A second passenger, seated BEFORE the morning moves into the locked window — because a new
     * seat REQUEST inside it is refused by the booking deadline, which is correct and separate. A
     * seat can still be confirmed post-lock through a waiting-list offer, and that clause of v5 is
     * covered in `ChangeProposalTest`: "is not unanimous once somebody new is confirmed on the
     * day". What matters here is that unanimity counts everybody once the window has closed.
     */
    /*
     * Only the departure moves, never `trip_date`: pushing the date two days out would collide with
     * a day this commute already has — `(commute_offer_id, trip_date)` is unique — and both the
     * lock and the booking deadline read the instant, not the date.
     */
    ScheduledTrip::query()->whereKey($this->trip->id)->update([
        'departure_at' => now()->addHours(48),
        'booking_deadline_at' => now()->addHours(36),
    ]);

    $second = verifiedPassenger('01222220007', device: 'pax-7');

    approveSeat(
        $this->driverToken,
        requestSeat($second, $this->commuteId, ['scheduledTripId' => $this->trip->id])
            ->assertStatus(201)->json('data.id'),
    );

    // Back inside the lock, with two people aboard.
    ScheduledTrip::query()->whereKey($this->trip->id)->update([
        'departure_at' => now()->addHours(8),
    ]);

    $proposalId = proposeDeparture($this->driverToken, $this->commuteId, $this->trip->id, '07:40:00')
        ->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/change-proposals/{$proposalId}/respond", ['response' => 'accepted'])
        ->assertOk();

    /*
     * One yes is not unanimity, locked window or not. Asserted as "not yet the proposed time"
     * rather than against a literal: the fixture moves the departure around to get in and out of
     * the window, so the original wall clock is whatever that left behind.
     */
    expect(ChangeProposal::query()->whereKey($proposalId)->sole()->status)
        ->toBe(ChangeProposalStatus::Pending)
        ->and(ScheduledTrip::query()->whereKey($this->trip->id)->sole()->departure_local->format('H:i'))
        ->not->toBe('07:40');

    test()->flushHeaders()->withToken($second)
        ->postJson("/api/v1/change-proposals/{$proposalId}/respond", ['response' => 'accepted'])
        ->assertOk();

    expect(ScheduledTrip::query()->whereKey($this->trip->id)->sole()->departure_local->format('H:i'))
        ->toBe('07:40');
});
