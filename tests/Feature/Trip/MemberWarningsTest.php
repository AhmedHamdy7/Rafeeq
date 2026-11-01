<?php

use App\Domains\Admin\Actions\ResolveFeeAppealAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Actions\ChargePendingFeesAction;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Trip\Actions\RecordMemberWarningAction;
use App\Domains\Trip\Enums\WarningFeeStatus;
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
 * 🔴 PAY-22: a lateness gets two warnings, then 10 %, 20 %, 25 % of the seat price.
 */
it('charges a lateness from the third, up the ladder to its cap', function () {
    $bookings = Booking::factory()->count(6)->create(['passenger_user_id' => $this->pax->id]);

    $recorded = [];

    foreach ($bookings as $booking) {
        $recorded[] = app(RecordMemberWarningAction::class)
            ->record($this->pax, WarningKind::LateBoarding, $booking, now()->toDateString());
    }

    $price = $bookings[0]->price_snapshot_piastres;

    expect(array_map(fn ($w) => $w->ordinal, $recorded))->toBe([1, 2, 3, 4, 5, 6])
        ->and(array_map(fn ($w) => $w->fee_basis_points, $recorded))->toBe([0, 0, 1000, 2000, 2500, 2500])
        ->and($recorded[2]->fee_piastres)->toBe(intdiv($price * 10, 100))
        ->and($recorded[2]->fee_status)->toBe(WarningFeeStatus::Charged)
        ->and(DebtLedger::owedBy($this->pax, DebtSide::Passenger))->toBe(intdiv($price * 10, 100) + intdiv($price * 20, 100) + 2 * intdiv($price * 25, 100));
});

/**
 * 🔴 PAY-20: a no-show gets ONE warning, then 30 %, 50 %, 75 %, 100 %.
 */
it('charges a no-show from the second, up to the whole seat', function () {
    $bookings = Booking::factory()->count(5)->create(['passenger_user_id' => $this->pax->id]);

    $recorded = [];

    foreach ($bookings as $booking) {
        $recorded[] = app(RecordMemberWarningAction::class)
            ->record($this->pax, WarningKind::NoShow, $booking, now()->toDateString());
    }

    expect(array_map(fn ($w) => $w->fee_basis_points, $recorded))->toBe([0, 3000, 5000, 7500, 10000])
        ->and($recorded[4]->fee_piastres)->toBe($bookings[4]->price_snapshot_piastres);
});

/**
 * 🔴 Decision B4: three counters, none feeding another. Two latenesses and a no-show is a first
 * no-show, not a third strike.
 */
it('counts the kinds separately', function () {
    $bookings = Booking::factory()->count(3)->create(['passenger_user_id' => $this->pax->id]);

    $action = app(RecordMemberWarningAction::class);

    $action->record($this->pax, WarningKind::LateBoarding, $bookings[0]);
    $action->record($this->pax, WarningKind::LateBoarding, $bookings[1]);

    $missed = $action->record($this->pax, WarningKind::NoShow, $bookings[2]);

    expect($missed->ordinal)->toBe(1)
        ->and($missed->fee_piastres)->toBe(0);
});

/**
 * 🔴 PAY-20: ninety days without one starts the counter again.
 */
it('starts a counter again after 90 days without an event', function () {
    $bookings = Booking::factory()->count(3)->create(['passenger_user_id' => $this->pax->id]);
    $action = app(RecordMemberWarningAction::class);

    $action->record($this->pax, WarningKind::NoShow, $bookings[0], now()->subDays(200)->toDateString());
    $second = $action->record($this->pax, WarningKind::NoShow, $bookings[1], now()->subDays(150)->toDateString());
    $fresh = $action->record($this->pax, WarningKind::NoShow, $bookings[2], now()->toDateString());

    expect($second->ordinal)->toBe(2)
        ->and($fresh->ordinal)->toBe(1)
        ->and($fresh->fee_piastres)->toBe(0);
});

/**
 * A no-show is told immediately: there is no journey to wait for, because the whole point is that
 * she is not on it.
 */
it('tells her about a no-show as it is recorded', function () {
    underway($this->driverToken, $this->tripId);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])
        ->assertOk();

    $warning = MemberWarning::query()->where('kind', WarningKind::NoShow->value)->sole();

    expect($warning->delivered_at)->not->toBeNull()
        ->and(Notification::query()->where('user_id', $this->pax->id)
            ->where('type', 'missed_commitment_warning')->count())->toBe(1);
});

/**
 * PAY-21: within 12 hours of departure a cancellation is a late cancel, on its own counter.
 */
it('counts a cancellation within 12 hours as a late cancel', function () {
    runLeavingIn($this->tripId, 11 * 60);

    test()->flushHeaders()->withToken($this->paxToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel", ['reason' => 'Overslept'])
        ->assertOk();

    expect(warningsFor($this->pax->id, WarningKind::LateCancel))->toBe(1)
        ->and(warningsFor($this->pax->id, WarningKind::NoShow))->toBe(0);
});

/**
 * 🔴 PAY-21, R-8: the late-cancel fee waits for departure, and costs nothing if somebody from the
 * waiting list takes the seat.
 */
it('holds a late-cancel fee until departure, and drops it if the seat is refilled', function () {
    $earlier = Booking::factory()->create(['passenger_user_id' => $this->pax->id]);
    app(RecordMemberWarningAction::class)->record($this->pax, WarningKind::LateCancel, $earlier, now()->toDateString());

    runLeavingIn($this->tripId, 60);

    test()->flushHeaders()->withToken($this->paxToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel", ['reason' => 'Overslept'])
        ->assertOk();

    $warning = MemberWarning::query()->where('booking_id', $this->bookingId)->sole();

    expect($warning->ordinal)->toBe(2)
        ->and($warning->fee_status)->toBe(WarningFeeStatus::Pending)
        ->and(DebtLedger::owedBy($this->pax, DebtSide::Passenger))->toBe(0);

    // Somebody else takes the seat, then the car leaves.
    Booking::factory()->create(['scheduled_trip_id' => $this->tripId]);
    ScheduledTrip::query()->whereKey($this->tripId)->update(['departure_at' => now()->subMinute()]);

    app(ChargePendingFeesAction::class)->execute();

    expect($warning->refresh()->fee_status)->toBe(WarningFeeStatus::Waived)
        ->and(DebtLedger::owedBy($this->pax, DebtSide::Passenger))->toBe(0);
});

it('charges the late-cancel fee at departure when the seat stayed empty', function () {
    $earlier = Booking::factory()->create(['passenger_user_id' => $this->pax->id]);
    app(RecordMemberWarningAction::class)->record($this->pax, WarningKind::LateCancel, $earlier, now()->toDateString());

    runLeavingIn($this->tripId, 60);

    test()->flushHeaders()->withToken($this->paxToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel", ['reason' => 'Overslept'])
        ->assertOk();

    ScheduledTrip::query()->whereKey($this->tripId)->update(['departure_at' => now()->subMinute()]);

    app(ChargePendingFeesAction::class)->execute();
    app(ChargePendingFeesAction::class)->execute();

    $warning = MemberWarning::query()->where('booking_id', $this->bookingId)->sole();
    $price = Booking::query()->findOrFail($this->bookingId)->price_snapshot_piastres;

    // The second rung of the no-show ladder is 30 %; a late cancel is a quarter of it: 7.5 %.
    expect($warning->fee_status)->toBe(WarningFeeStatus::Charged)
        ->and($warning->fee_basis_points)->toBe(750)
        ->and(DebtLedger::owedBy($this->pax, DebtSide::Passenger))->toBe(intdiv($price * 750, 10_000));
});

/**
 * 🔴 PAY-10: an appeal that wins before she paid removes the fee; the ladder forgets the warning.
 */
it('takes the fee off her debt when staff overturn her appeal', function () {
    $bookings = Booking::factory()->count(2)->create(['passenger_user_id' => $this->pax->id]);
    $action = app(RecordMemberWarningAction::class);
    $action->record($this->pax, WarningKind::NoShow, $bookings[0]);
    $charged = $action->record($this->pax, WarningKind::NoShow, $bookings[1]);

    expect(DebtLedger::owedBy($this->pax, DebtSide::Passenger))->toBe($charged->fee_piastres);

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/account/warnings/{$charged->getKey()}/dispute", ['reason' => 'I was at the pickup point on time.'])
        ->assertOk();

    app(ResolveFeeAppealAction::class)->execute($charged, AdminUser::factory()->create(), overturn: true, note: 'GPS shows she was there.');

    expect($charged->refresh()->fee_status)->toBe(WarningFeeStatus::Reversed)
        ->and(DebtLedger::owedBy($this->pax, DebtSide::Passenger))->toBe(0);
});

it('refuses an appeal after 48 hours', function () {
    $warning = MemberWarning::factory()->create(['user_id' => $this->pax->id, 'delivered_at' => now()->subHours(49)]);

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/account/warnings/{$warning->getKey()}/dispute", ['reason' => 'too late'])
        ->assertStatus(422)
        ->assertJsonPath('error.fields.dispute.0', 'APPEAL_WINDOW_CLOSED');
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
        ->assertJsonPath('meta.standing.NO_SHOW', 0)
        ->assertJsonPath('meta.standing.LATE_CANCEL', 0);
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
 * The amount, now that the ladders are decided (PAY-20 → PAY-22): frozen on the warning.
 */
it('shows the fee a warning carries', function () {
    MemberWarning::factory()->feeEligible()->create(['user_id' => $this->pax->id]);

    $payload = test()->withToken($this->paxToken)
        ->getJson('/api/v1/account/warnings')->assertOk()->json('data.0');

    expect($payload['feeEligible'])->toBeTrue()
        ->and($payload['feePiastres'])->toBe(800)
        ->and($payload['feeStatus'])->toBe('charged')
        ->and($payload['appealBy'])->not->toBeNull();
});
