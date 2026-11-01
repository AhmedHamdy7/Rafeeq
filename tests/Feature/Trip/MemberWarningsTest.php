<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Trip\Actions\RecordMemberWarningAction;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\MemberWarning;
use App\Domains\Trip\Models\TripWaitTimer;
use Illuminate\Support\Facades\Storage;

/**
 * The two escalating warning counters (Feature 03 v5 §239 and §10).
 *
 * > *"Track lateness incident separately: 1st warning, 2nd warning, 3rd fee eligible (amount not
 * > shown until Payments rules). **Avoid confusing with the distinct no-show/late-cancel counter
 * > (first warning, second fee).** Provide clear explanation when warnings displayed, with dispute
 * > link."*
 *
 * > *"**do not show any lateness message during the trip.** After drop-off the passenger receives a
 * > push-style notification."*
 *
 * 🔴 Two things here are about restraint rather than behaviour, and both are v5's instructions: the
 * two counters must not be confused with each other, and a lateness must not be mentioned while the
 * woman it is about is sitting in the car beside the people she kept waiting.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');

    $this->bookingId = approveSeat(
        $this->driverToken,
        requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
            ->assertStatus(201)->json('data.id'),
    );

    $this->pax = User::query()->where('phone_e164', '+201223339999')->sole();
});

/**
 * Gets her marked LATE: a wait timer has to have been running when she turned up, which is how
 * `ConfirmAttendanceAction` decides lateness — from the clock, never from the driver's judgement.
 */
function boardedLate(string $driverToken, string $tripId, string $bookingId): void
{
    underway($driverToken, $tripId);

    $timerId = test()->flushHeaders()->withToken($driverToken)
        ->postJson("/api/v1/trips/{$tripId}/wait-timers", ['bookingId' => $bookingId, ...atPickupOf($bookingId)])
        ->assertStatus(201)->json('data.id');

    // Her grace had started: the car was waiting past her pickup time when she got in.
    TripWaitTimer::query()->whereKey($timerId)->update(['grace_starts_at' => now()->subMinute()]);

    checkIn($driverToken, $tripId, ['bookingId' => $bookingId])->assertOk();
}

function warningsFor(string $userId, WarningKind $kind): int
{
    return MemberWarning::query()->where('user_id', $userId)->where('kind', $kind->value)->count();
}

/*
|--------------------------------------------------------------------------
| Lateness — recorded at pickup, delivered after drop-off
|--------------------------------------------------------------------------
*/

/**
 * 🔴 v5 §10, the whole reason the two steps are separate: she is recorded as late and told
 * **nothing** while the journey is happening.
 */
it('records a lateness without saying a word during the trip', function () {
    boardedLate($this->driverToken, $this->tripId, $this->bookingId);

    $warning = MemberWarning::query()->sole();

    expect($warning->kind)->toBe(WarningKind::LateBoarding)
        ->and($warning->ordinal)->toBe(1)
        ->and($warning->fee_eligible)->toBeFalse()
        // 🔴 Recorded and NOT delivered. This is the gap v5 asks for.
        ->and($warning->delivered_at)->toBeNull();

    // Nothing has reached her.
    expect(Notification::query()->where('user_id', $this->pax->id)
        ->where('type', 'late_boarding_warning')->count())->toBe(0);
});

/**
 * ⚠️ And it is not reachable through the list either, which would be the same message through a
 * different door.
 */
it('hides an undelivered warning from her own list', function () {
    boardedLate($this->driverToken, $this->tripId, $this->bookingId);

    test()->flushHeaders()->withToken($this->paxToken)
        ->getJson('/api/v1/account/warnings')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.standing.LATE_BOARDING', 0);
});

/**
 * 🔴 And it arrives when her journey ends, with the explanation v5 words almost exactly: which
 * warning, the day, what comes next, and that she may dispute it.
 */
it('tells her once she has been set down', function () {
    boardedLate($this->driverToken, $this->tripId, $this->bookingId);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/drop-off", ['bookingId' => $this->bookingId])
        ->assertOk();

    $notification = Notification::query()
        ->where('user_id', $this->pax->id)
        ->where('type', 'late_boarding_warning')
        ->sole();

    expect($notification->title)->toContain('1')
        // The explanation, not a bare "warning recorded".
        ->and($notification->body)->toContain('5-minute grace')
        ->and($notification->body)->toContain('3rd may lead to a fee')
        // 🔴 The dispute link v5 requires.
        ->and($notification->data['warningId'])->toBe(MemberWarning::query()->sole()->getKey())
        // Falsy rather than strictly false: the data column is JSON, and a boolean that has been
        // through it is not the thing a strict comparison would catch.
        ->and($notification->data['feeEligible'])->toBeFalsy();

    expect(MemberWarning::query()->sole()->delivered_at)->not->toBeNull();
});

/**
 * Completing the whole run sets everybody down at once, so it delivers too — otherwise a warning
 * on a passenger the driver never tapped `Dropped off` for would wait for ever.
 */
it('tells her when the driver closes the whole run instead', function () {
    boardedLate($this->driverToken, $this->tripId, $this->bookingId);

    // A run is only completable once it is actually carrying people.
    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    expect(Notification::query()->where('user_id', $this->pax->id)
        ->where('type', 'late_boarding_warning')->count())->toBe(1);
});

/**
 * 🔴 Once per morning, however many times a drop-off is retried. "Twice" is not cosmetic when the
 * third one makes a fee possible.
 */
it('records and delivers one warning per morning', function () {
    boardedLate($this->driverToken, $this->tripId, $this->bookingId);

    foreach ([1, 2, 3] as $ignored) {
        test()->flushHeaders()->withToken($this->driverToken)
            ->postJson("/api/v1/trips/{$this->tripId}/drop-off", ['bookingId' => $this->bookingId]);
    }

    expect(MemberWarning::query()->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $this->pax->id)
            ->where('type', 'late_boarding_warning')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The escalation, and the two counters kept apart
|--------------------------------------------------------------------------
*/

/**
 * 🔴 v5's numbers: a lateness gets two warnings before a fee becomes possible.
 */
it('makes a fee possible on the third lateness and not before', function () {
    $bookings = Booking::factory()->count(3)->create(['passenger_user_id' => $this->pax->id]);

    $recorded = [];

    foreach ($bookings as $booking) {
        $recorded[] = app(RecordMemberWarningAction::class)
            ->record($this->pax, WarningKind::LateBoarding, $booking);
    }

    expect(array_map(fn ($w) => $w->ordinal, $recorded))->toBe([1, 2, 3])
        ->and(array_map(fn ($w) => $w->fee_eligible, $recorded))->toBe([false, false, true]);
});

/**
 * 🔴 And v5's other numbers, which it tells us not to confuse with the first: a missed commute gets
 * ONE warning before a fee becomes possible. They are not degrees of the same thing.
 */
it('makes a fee possible on the second missed commute', function () {
    $bookings = Booking::factory()->count(2)->create(['passenger_user_id' => $this->pax->id]);

    $recorded = [];

    foreach ($bookings as $booking) {
        $recorded[] = app(RecordMemberWarningAction::class)
            ->record($this->pax, WarningKind::MissedCommitment, $booking);
    }

    expect(array_map(fn ($w) => $w->fee_eligible, $recorded))->toBe([false, true]);
});

/**
 * 🔴 The counters do not feed each other. Two latenesses and a missed commute is a first missed
 * commute, not a third strike.
 */
it('counts the two kinds separately', function () {
    $bookings = Booking::factory()->count(3)->create(['passenger_user_id' => $this->pax->id]);

    $action = app(RecordMemberWarningAction::class);

    $action->record($this->pax, WarningKind::LateBoarding, $bookings[0]);
    $action->record($this->pax, WarningKind::LateBoarding, $bookings[1]);

    $missed = $action->record($this->pax, WarningKind::MissedCommitment, $bookings[2]);

    expect($missed->ordinal)->toBe(1)
        ->and($missed->fee_eligible)->toBeFalse();
});

/**
 * A no-show is told immediately: there is no journey to wait for, because the whole point is that
 * she is not on it.
 */
it('tells her about a missed commute as it is recorded', function () {
    underway($this->driverToken, $this->tripId);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])
        ->assertOk();

    $warning = MemberWarning::query()->where('kind', WarningKind::MissedCommitment->value)->sole();

    expect($warning->delivered_at)->not->toBeNull()
        ->and(Notification::query()->where('user_id', $this->pax->id)
            ->where('type', 'missed_commitment_warning')->count())->toBe(1);
});

/**
 * A late cancellation lands on the same counter — the seat was just as empty either way.
 */
it('counts a late cancellation as a missed commute', function () {
    runLeavingIn($this->tripId, 60);

    test()->flushHeaders()->withToken($this->paxToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel", ['reason' => 'Overslept'])
        ->assertOk();

    expect(warningsFor($this->pax->id, WarningKind::MissedCommitment))->toBe(1);
});

/**
 * ⚠️ And cancelling a week out costs her nothing at all — the driver can still fill the seat.
 */
it('says nothing about a cancellation made in good time', function () {
    /*
     * Only the departure is moved, not `trip_date`. Pushing the date five days out would land on a
     * day this commute already has a trip for — `(commute_offer_id, trip_date)` is unique — and the
     * late-cancellation rule reads `departure_at` and nothing else.
     */
    ScheduledTrip::query()->whereKey($this->tripId)->update([
        'departure_at' => now()->addDays(5),
        'booking_deadline_at' => now()->addDays(4),
    ]);

    test()->flushHeaders()->withToken($this->paxToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel", ['reason' => 'Plans changed'])
        ->assertOk();

    expect(MemberWarning::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Her side of it
|--------------------------------------------------------------------------
*/

/**
 * v5: *"Provide clear explanation when warnings displayed."* The payload carries the explanation
 * rather than leaving two clients to word the same warning differently.
 */
it('explains each warning rather than publishing a bare count', function () {
    MemberWarning::factory()->create(['user_id' => $this->pax->id]);

    test()->withToken($this->paxToken)
        ->getJson('/api/v1/account/warnings')
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'LATE_BOARDING')
        ->assertJsonPath('data.0.ordinal', 1)
        // How many come before a fee, sent rather than hard-coded in the app.
        ->assertJsonPath('data.0.warningsBeforeFee', 2)
        ->assertJsonPath('data.0.feeEligible', false)
        ->assertJsonPath('data.0.stillStands', true)
        ->assertJsonPath('data.0.canDispute', true)
        ->assertJsonPath('meta.standing.LATE_BOARDING', 1)
        ->assertJsonPath('meta.standing.MISSED_COMMITMENT', 0);
});

/**
 * 🔒 Hers only. A warning is an accusation, and reading somebody else's would be reading a private
 * record of a morning she let people down.
 */
it('shows her nobody else’s warnings', function () {
    $other = verifiedPassenger('01299887766', device: 'pax-9');

    MemberWarning::factory()->create(['user_id' => $this->pax->id]);

    test()->flushHeaders()->withToken($other)
        ->getJson('/api/v1/account/warnings')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/**
 * 🔴 The dispute link v5 requires beside every displayed warning.
 */
it('lets her answer a warning once', function () {
    $warning = MemberWarning::factory()->create(['user_id' => $this->pax->id]);

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/account/warnings/{$warning->getKey()}/dispute", [
            'reason' => 'السواقة وصلت قبل الميعاد بعشر دقايق',
        ])
        ->assertOk()
        ->assertJsonPath('data.disputedAt', fn ($v) => $v !== null)
        ->assertJsonPath('data.canDispute', false)
        // 🔒 Raising it does not pause the escalation — a reviewer decides.
        ->assertJsonPath('data.stillStands', true);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/account/warnings/{$warning->getKey()}/dispute", ['reason' => 'again'])
        ->assertStatus(422)
        ->assertJsonPath('error.fields.dispute.0', 'ALREADY_DISPUTED');
});

/**
 * 🔒 And she cannot answer somebody else's — a 404, never a 403, because a 403 would confirm the id
 * belongs to a real record about a real person.
 */
it('refuses a dispute on somebody else’s warning', function () {
    $other = verifiedPassenger('01299887766', device: 'pax-9');

    $warning = MemberWarning::factory()->create(['user_id' => $this->pax->id]);

    test()->flushHeaders()->withToken($other)
        ->postJson("/api/v1/account/warnings/{$warning->getKey()}/dispute", ['reason' => 'not mine'])
        ->assertStatus(404);

    expect(MemberWarning::query()->whereKey($warning->getKey())->sole()->disputed_at)->toBeNull();
});

/**
 * 🔴 An overturned warning stops counting and stays readable — so she can show she was cleared, and
 * so the next warning does not inherit its position.
 */
it('stops counting an overturned warning without deleting it', function () {
    $overturned = MemberWarning::factory()->create([
        'user_id' => $this->pax->id,
        'disputed_at' => now()->subDay(),
        'dispute_resolution' => 'overturned',
    ]);

    test()->withToken($this->paxToken)
        ->getJson('/api/v1/account/warnings')
        ->assertOk()
        // Still listed.
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.stillStands', false)
        // And not counted.
        ->assertJsonPath('meta.standing.LATE_BOARDING', 0);

    // The next one is a first warning again, not a second.
    $next = app(RecordMemberWarningAction::class)->record(
        $this->pax,
        WarningKind::LateBoarding,
        Booking::factory()->create(['passenger_user_id' => $this->pax->id]),
    );

    expect($next->ordinal)->toBe(1)
        ->and(MemberWarning::query()->whereKey($overturned->getKey())->exists())->toBeTrue();
});

/**
 * ⚠️ No amount, anywhere. v5: *"amount not shown until Payments rules"* — and the cancellation
 * policy is still deferred, so a number would be inventing the one thing v5 is most careful not to
 * state.
 */
it('publishes no fee amount', function () {
    MemberWarning::factory()->feeEligible()->create(['user_id' => $this->pax->id]);

    $payload = test()->withToken($this->paxToken)
        ->getJson('/api/v1/account/warnings')->assertOk()->json('data.0');

    expect($payload['feeEligible'])->toBeTrue()
        ->and(array_keys($payload))->not->toContain('feePiastres')
        ->and(json_encode($payload))->not->toContain('iastres');
});
