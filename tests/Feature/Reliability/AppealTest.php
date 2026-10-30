<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Reliability\Actions\AppealReliabilityEventAction;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Reliability\Support\ReliabilityLedger;
use Illuminate\Support\Facades\Storage;

/**
 * Appealing a reliability event (Feature 03 v5 §115).
 *
 * > *"Admin monthly reviews low Reliability/repeat violations for drivers and passengers;
 * > warning/contact, temporary suspension, permanent person/account/phone ban **with appeal**."*
 *
 * 🔴 The outcome has been storable since the ledger was built — `reversed_at`, `reversed_by`,
 * `reversed_reason` — and **nothing could ever write it**, because there was no way to file. An
 * appeal that cannot be filed is not an appeal, and a number that can end an account with no way to
 * answer it is exactly what v5 attaches the word to.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->token = verifiedPassenger('01112223344');
    $this->member = User::query()->where('phone_e164', '+201112223344')->sole();

    $this->event = ReliabilityEvent::factory()->create([
        'user_id' => $this->member->id,
        'kind' => ReliabilityEventKind::PassengerNoShow,
    ]);
});

function appealEvent(string $token, string $eventId, string $reason = 'كنت في المستشفى ومعايا ورق')
{
    return test()->flushHeaders()->withToken($token)
        ->postJson("/api/v1/account/reliability/events/{$eventId}/appeal", ['reason' => $reason]);
}

it('lets her file one, and says plainly that nothing has changed yet', function () {
    appealEvent($this->token, $this->event->getKey())
        ->assertOk()
        ->assertJsonPath('data.reliabilityEventId', $this->event->getKey())
        ->assertJsonPath('data.appealedAt', fn ($v) => $v !== null)
        /*
         * 🔴 The thing most likely to be misunderstood, stated back to her: she has been heard, and
         * the event still counts. If filing paused the consequence, everything would be appealed as
         * a matter of course and the score would stop meaning anything.
         */
        ->assertJsonPath('data.stillCounts', true);

    $event = ReliabilityEvent::query()->whereKey($this->event->getKey())->sole();

    expect($event->appeal_reason)->toBe('كنت في المستشفى ومعايا ورق')
        ->and($event->reversed_at)->toBeNull();
});

/**
 * And her score does not move on the strength of having asked.
 */
it('does not move her score when she files', function () {
    $before = ReliabilityLedger::scoreFor((string) $this->member->id, $this->event->role);

    appealEvent($this->token, $this->event->getKey())->assertOk();

    expect(ReliabilityLedger::scoreFor((string) $this->member->id, $this->event->role))->toBe($before);
});

/** v5's "appeal once". */
it('takes one appeal per event', function () {
    appealEvent($this->token, $this->event->getKey())->assertOk();

    appealEvent($this->token, $this->event->getKey())
        ->assertStatus(422)
        ->assertJsonPath('error.fields.appeal.0', 'ALREADY_APPEALED');
});

it('requires her to say what happened', function () {
    test()->withToken($this->token)
        ->postJson("/api/v1/account/reliability/events/{$this->event->getKey()}/appeal", [])
        ->assertStatus(422);
});

/**
 * 🔒 Somebody else's event is a 404, never a 403 — a 403 would confirm the id names a real
 * judgement about a real person, which turns an id into an oracle for whose record exists.
 */
it('hides somebody else’s event', function () {
    $other = verifiedPassenger('01299887766', device: 'pax-9');

    appealEvent($other, $this->event->getKey())->assertStatus(404);

    expect(ReliabilityEvent::query()->whereKey($this->event->getKey())->sole()->appealed_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The reviewer's answer
|--------------------------------------------------------------------------
*/

/**
 * 🔴 An approved appeal REVERSES rather than deletes. The row stays readable with its weight and
 * reason intact — which is what lets her show she was cleared, and lets a later reviewer see the
 * first one was wrong.
 */
it('stops the event counting when a reviewer approves, and keeps it readable', function () {
    appealEvent($this->token, $this->event->getKey())->assertOk();

    $reviewer = adminWithRole(AdminRole::SafetyLead);

    $before = ReliabilityLedger::scoreFor((string) $this->member->id, $this->event->role);

    app(AppealReliabilityEventAction::class)->approve(
        $reviewer,
        $this->event->refresh(),
        'Hospital note checked out.',
    );

    $event = ReliabilityEvent::query()->whereKey($this->event->getKey())->sole();

    expect($event->reversed_at)->not->toBeNull()
        ->and($event->reversed_by)->toBe($reviewer->getKey())
        ->and($event->counts())->toBeFalse()
        // Still there, with what it originally said.
        ->and($event->weight)->toBeGreaterThan(0)
        ->and($event->appeal_reason)->not->toBeNull();

    // And her standing improves by exactly what the event cost.
    expect(ReliabilityLedger::scoreFor((string) $this->member->id, $this->event->role))
        ->toBeGreaterThan($before);
});

it('leaves it counting when a reviewer declines', function () {
    appealEvent($this->token, $this->event->getKey())->assertOk();

    $before = ReliabilityLedger::scoreFor((string) $this->member->id, $this->event->role);

    app(AppealReliabilityEventAction::class)->decline(
        adminWithRole(AdminRole::SafetyLead),
        $this->event->refresh(),
        'No evidence of the circumstances described.',
    );

    $event = ReliabilityEvent::query()->whereKey($this->event->getKey())->sole();

    expect($event->appeal_declined_at)->not->toBeNull()
        ->and($event->reversed_at)->toBeNull()
        ->and($event->counts())->toBeTrue()
        ->and(ReliabilityLedger::scoreFor((string) $this->member->id, $this->event->role))->toBe($before);
});

/**
 * She is told either way — an appeal answered in silence is an appeal nobody made.
 */
it('tells her what was decided', function (string $decision, string $type) {
    appealEvent($this->token, $this->event->getKey())->assertOk();

    app(AppealReliabilityEventAction::class)->{$decision}(
        adminWithRole(AdminRole::SafetyLead),
        $this->event->refresh(),
        'A note written for the desk about another member.',
    );

    $notification = Notification::query()
        ->where('user_id', $this->member->id)
        ->where('type', $type)
        ->sole();

    /*
     * 🔒 And the reviewer's note is NOT in it. It is written for the desk — it may name another
     * member, quote a driver, or record something about an investigation — so she is told the
     * outcome and support explains the rest.
     */
    expect($notification->body)->not->toContain('another member')
        ->and($notification->data['reliabilityEventId'])->toBe($this->event->getKey());
})->with([
    ['approve', 'appeal_approved'],
    ['decline', 'appeal_declined'],
]);

/**
 * The reviewer's queue is "filed and answered neither way" — the absence of both outcomes rather
 * than a status somebody has to remember to set, so an appeal cannot fall out of it.
 */
it('lists an appeal as open only until it is answered', function () {
    expect(ReliabilityEvent::query()->awaitingAppealReview()->count())->toBe(0);

    appealEvent($this->token, $this->event->getKey())->assertOk();

    expect(ReliabilityEvent::query()->awaitingAppealReview()->count())->toBe(1);

    app(AppealReliabilityEventAction::class)->decline(
        adminWithRole(AdminRole::SafetyLead),
        $this->event->refresh(),
        'Reviewed and declined.',
    );

    expect(ReliabilityEvent::query()->awaitingAppealReview()->count())->toBe(0);
});

/**
 * ⚠️ A reversed event cannot be appealed again — there is nothing left to ask for.
 */
it('refuses an appeal on an event that has already been cleared', function () {
    app(AppealReliabilityEventAction::class)->approve(
        adminWithRole(AdminRole::SafetyLead),
        $this->event,
        'Cleared on review.',
    );

    appealEvent($this->token, $this->event->getKey())
        ->assertStatus(422)
        ->assertJsonPath('error.fields.appeal.0', 'ALREADY_REVERSED');
});
