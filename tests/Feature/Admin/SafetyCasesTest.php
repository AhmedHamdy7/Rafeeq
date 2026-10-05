<?php

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Safety\Enums\SosResolution;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\SafetyEvent;
use App\Domains\Safety\Models\SosEvent;
use App\Livewire\Admin\SafetyCases;
use App\Livewire\Admin\VerificationQueue;
use Livewire\Livewire;

/**
 * The safety desk (Phase 13, the SAFETY CASES section).
 *
 * 🔴 Until this page existed an SOS was recorded and read by nobody: `first_touch_at`, the
 * number the Bible calls the most important metric operations has, could only ever be null,
 * and a member's app could only ever say that nobody had responded. These tests are about
 * the other half of that record — a human picking it up, and the member being able to see it.
 */
beforeEach(function () {
    fakeOtpSender();

    $this->member = signIn('01112223344')['session']['accessToken'];
    $this->lead = adminWithRole(AdminRole::SafetyLead);
});

function raiseSos(string $token, array $payload = []): SosEvent
{
    $id = test()->withToken($token)->postJson('/api/v1/sos', $payload)->assertStatus(201)->json('data.id');

    return SosEvent::query()->whereKey($id)->sole();
}

function fileReport(string $token, string $category = 'harassment'): Incident
{
    $id = test()->withToken($token)->postJson('/api/v1/incidents', [
        'category' => $category,
        'description' => 'حصل معايا تصرف مش مقبول في العربية.',
    ])->assertStatus(201)->json('data.id');

    return Incident::query()->whereKey($id)->sole();
}

/*
|--------------------------------------------------------------------------
| Live alerts
|--------------------------------------------------------------------------
*/

it('shows a live SOS to the safety desk, marked as nobody on it', function () {
    raiseSos($this->member);

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)
        ->assertOk()
        ->assertSee(__('admin.safety.nobody_on_it'))
        ->assertSee(__('admin.safety.acknowledge'));
});

/**
 * 🔒 The instruction that changes what the operator does next: phoning somebody who raised
 * a silent alert can be the most dangerous thing anyone does that night.
 */
it('tells the operator not to phone somebody who raised a silent alert', function () {
    raiseSos($this->member, ['isDiscreet' => true]);

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)->assertSee(__('admin.safety.silent_hint'));
});

it('records who picked an alert up and when, and the member sees that somebody has', function () {
    $sos = raiseSos($this->member);

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)
        ->call('acknowledge', $sos->id)
        ->assertHasNoErrors();

    $sos->refresh();

    expect($sos->first_touch_at)->not->toBeNull()
        ->and($sos->responder_admin_id)->toBe($this->lead->id);

    // The member's own app now reads `respondedAt` — the line that says a human is looking.
    test()->withToken($this->member)->postJson("/api/v1/sos/{$sos->id}/cancel")
        ->assertStatus(409)
        ->assertJsonPath('error.fields.respondedAt.0', fn ($value) => $value !== null);
});

/**
 * 🔒 Written twice on purpose: once for "what did this operator do", once into the timeline
 * of the emergency itself, in the table nobody may delete.
 */
it('writes the pick-up to the audit trail and to the emergency timeline', function () {
    $sos = raiseSos($this->member);

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)->call('acknowledge', $sos->id);

    expect(AdminAction::query()->where('action', 'sos.acknowledge')->sole()->admin_id)->toBe($this->lead->id);

    $timeline = SafetyEvent::query()->where('type', SafetyEventType::AdminIntervention->value)->sole();

    expect($timeline->user_id)->toBe($sos->safetyEvent->user_id)
        ->and($timeline->metadata)->toMatchArray(['sosId' => $sos->id, 'step' => 'acknowledged']);
});

/**
 * 🔴 Two operators reaching for the same alert must not both be told it is theirs: each would
 * stand down believing the other is on the phone.
 */
it('gives an alert to the first operator who picks it up and tells the second', function () {
    $sos = raiseSos($this->member);
    $colleague = adminWithRole(AdminRole::SafetyLead);

    actingAsAdmin($this->lead);
    Livewire::test(SafetyCases::class)->call('acknowledge', $sos->id)->assertHasNoErrors();

    actingAsAdmin($colleague);
    Livewire::test(SafetyCases::class)
        ->call('acknowledge', $sos->id)
        ->assertHasErrors('alerts');

    expect($sos->refresh()->responder_admin_id)->toBe($this->lead->id);
});

it('treats a second click by the same operator as nothing', function () {
    $sos = raiseSos($this->member);

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)
        ->call('acknowledge', $sos->id)
        ->call('acknowledge', $sos->id)
        ->assertHasNoErrors();

    expect(AdminAction::query()->where('action', 'sos.acknowledge')->count())->toBe(1);
});

/**
 * A response time written when the case is closed measures the paperwork, not the response.
 */
it('refuses to record how an alert ended before anybody picked it up', function () {
    $sos = raiseSos($this->member);

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)
        ->set('resolution', SosResolution::FalseAlarm->value)
        ->set('note', 'Called back, pocket press, member is fine.')
        ->call('resolveAlert', $sos->id)
        ->assertHasErrors('note');

    expect($sos->refresh()->resolution)->toBeNull();
});

it('closes an alert with an outcome and a note, and takes it off the desk', function () {
    $sos = raiseSos($this->member);

    actingAsAdmin($this->lead);

    $page = Livewire::test(SafetyCases::class)->call('acknowledge', $sos->id);

    // A note is required: this row is the record of an emergency.
    $page->set('resolution', SosResolution::Resolved->value)
        ->call('resolveAlert', $sos->id)
        ->assertHasErrors('note');

    $page->set('note', 'Spoke to the member, she is at the office and safe.')
        ->call('resolveAlert', $sos->id)
        ->assertHasNoErrors()
        ->assertSee(__('admin.safety.no_alerts'));

    expect($sos->refresh()->resolution)->toBe(SosResolution::Resolved)
        ->and(AdminAction::query()->where('action', 'sos.resolve')->sole()->reason)->toContain('safe');
});

it('refuses an outcome that is not one of the three', function () {
    $sos = raiseSos($this->member);

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)
        ->call('acknowledge', $sos->id)
        ->set('resolution', 'ignored')
        ->set('note', 'Not a real outcome at all, should be refused.')
        ->call('resolveAlert', $sos->id)
        ->assertHasErrors('resolution');
});

it('lets a colleague close an alert somebody else picked up, so a shift change does not strand it', function () {
    $sos = raiseSos($this->member);
    $colleague = adminWithRole(AdminRole::SafetyLead);

    actingAsAdmin($this->lead);
    Livewire::test(SafetyCases::class)->call('acknowledge', $sos->id);

    actingAsAdmin($colleague);
    Livewire::test(SafetyCases::class)
        ->set('resolution', SosResolution::FalseAlarm->value)
        ->set('note', 'Handover from the night shift, confirmed a pocket press.')
        ->call('resolveAlert', $sos->id)
        ->assertHasNoErrors();

    expect(AdminAction::query()->where('action', 'sos.resolve')->sole()->admin_id)->toBe($colleague->id);
});

it('drops an alert the member took back, and refuses to act on it', function () {
    $sos = raiseSos($this->member);

    test()->withToken($this->member)->postJson("/api/v1/sos/{$sos->id}/cancel")->assertOk();

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)
        ->assertSee(__('admin.safety.no_alerts'))
        ->call('acknowledge', $sos->id)
        ->assertHasErrors('alerts');

    expect($sos->refresh()->first_touch_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Reports
|--------------------------------------------------------------------------
*/

it('assigns a report to whoever takes it and starts the review', function () {
    $report = fileReport($this->member);

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)->call('take', $report->id)->assertHasNoErrors();

    $report->refresh();

    expect($report->status)->toBe(IncidentStatus::UnderReview)
        ->and($report->assigned_admin_id)->toBe($this->lead->id);

    expect(AdminAction::query()->where('action', 'incident.assign')->sole()->new_value)
        ->toMatchArray(['status' => 'under_review', 'assigned_admin_id' => $this->lead->id]);
});

it('lets a colleague take a case over, and records who had it', function () {
    $report = fileReport($this->member);
    $colleague = adminWithRole(AdminRole::SafetyLead);

    actingAsAdmin($this->lead);
    Livewire::test(SafetyCases::class)->call('take', $report->id);

    actingAsAdmin($colleague);
    Livewire::test(SafetyCases::class)->call('take', $report->id)->assertHasNoErrors();

    expect($report->refresh()->assigned_admin_id)->toBe($colleague->id)
        ->and(AdminAction::query()->where('action', 'incident.assign')->latest('id')->first()->old_value)
        ->toMatchArray(['assigned_admin_id' => $this->lead->id]);
});

/**
 * 🔴 The resolution text is not an internal note. The reporter's own app returns it verbatim,
 * so this asserts it through the member's API rather than the database.
 */
it('resolves a report and the reporter reads exactly what the operator wrote', function () {
    $report = fileReport($this->member);
    $message = 'We spoke to the driver and have removed her from the platform.';

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)
        ->call('openDecision', $report->id, 'resolve')
        ->set('reply', $message)
        ->call('decide', $report->id)
        ->assertHasNoErrors();

    test()->withToken($this->member)->getJson("/api/v1/incidents/{$report->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'RESOLVED')
        ->assertJsonPath('data.resolution', $message)
        ->assertJsonPath('data.resolvedAt', fn ($value) => $value !== null);

    expect(AdminAction::query()->where('action', 'incident.resolve')->sole()->reason)->toBe($message);
});

/**
 * 🔒 The reason for an escalation is internal — it may name a police contact or a suspicion —
 * and must never reach the reporter's screen.
 */
it('keeps the reason for an escalation away from the reporter', function () {
    $report = fileReport($this->member);

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)
        ->call('openDecision', $report->id, 'escalate')
        ->set('reply', 'Second report about this driver this month, handing to the lead.')
        ->call('decide', $report->id)
        ->assertHasNoErrors();

    test()->withToken($this->member)->getJson("/api/v1/incidents/{$report->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'ESCALATED')
        ->assertJsonPath('data.resolution', null);

    // Whoever moved an unassigned case owns it.
    expect($report->refresh()->assigned_admin_id)->toBe($this->lead->id);
});

it('closes a report without action, still telling the reporter why', function () {
    $report = fileReport($this->member, 'lost_item');

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)
        ->call('openDecision', $report->id, 'close')
        ->set('reply', 'The driver searched the car and found nothing, sorry.')
        ->call('decide', $report->id)
        ->assertHasNoErrors();

    expect($report->refresh()->status)->toBe(IncidentStatus::Closed)
        ->and($report->resolution)->toContain('found nothing');
});

it('requires a message long enough to mean something', function () {
    $report = fileReport($this->member);

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)
        ->call('openDecision', $report->id, 'resolve')
        ->set('reply', 'done')
        ->call('decide', $report->id)
        ->assertHasErrors('reply');

    expect($report->refresh()->status)->toBe(IncidentStatus::Open);
});

it('refuses to act on a report that is already finished', function () {
    $report = fileReport($this->member);

    actingAsAdmin($this->lead);

    $page = Livewire::test(SafetyCases::class)
        ->call('openDecision', $report->id, 'resolve')
        ->set('reply', 'Handled with the driver directly, all sorted.')
        ->call('decide', $report->id);

    $page->call('openDecision', $report->id, 'close')
        ->set('reply', 'A second decision on a case that is already resolved.')
        ->call('decide', $report->id)
        ->assertHasErrors('reply');

    $page->call('take', $report->id)->assertHasErrors('reports');

    expect($report->refresh()->status)->toBe(IncidentStatus::Resolved);
});

it('never steps an escalated case back down', function () {
    expect(IncidentStatus::Escalated->canTransitionTo(IncidentStatus::UnderReview))->toBeFalse()
        ->and(IncidentStatus::Escalated->canTransitionTo(IncidentStatus::Escalated))->toBeFalse()
        ->and(IncidentStatus::Escalated->canTransitionTo(IncidentStatus::Resolved))->toBeTrue()
        ->and(IncidentStatus::Resolved->canTransitionTo(IncidentStatus::Closed))->toBeFalse();
});

/**
 * The deadline already encodes the severity, so the queue sorts on it alone: an overdue
 * "high" outranks a "critical" filed a minute ago, because the overdue one is the promise
 * already being broken.
 */
it('orders open reports by their deadline', function () {
    $lost = fileReport($this->member, 'lost_item');
    $harassment = fileReport($this->member, 'harassment');

    // The lost item has been waiting long enough to be overdue.
    $lost->forceFill(['sla_due_at' => now()->subHour()])->save();

    actingAsAdmin($this->lead);

    Livewire::test(SafetyCases::class)->assertSeeInOrder([
        __('admin.safety.categories.lost_item'),
        __('admin.safety.categories.harassment'),
    ]);
});

/*
|--------------------------------------------------------------------------
| Who may see and do what
|--------------------------------------------------------------------------
*/

it('keeps everybody without the safety permission off the page', function (AdminRole $role) {
    actingAsAdmin(adminWithRole($role));

    Livewire::test(SafetyCases::class)->assertForbidden();
})->with([
    'operations' => AdminRole::Operations,
    'verification' => AdminRole::Verification,
    'finance' => AdminRole::Finance,
    'support' => AdminRole::Support,
]);

it('lets somebody who may only read the desk read it, and nothing else', function () {
    $sos = raiseSos($this->member);
    $report = fileReport($this->member);

    $reader = adminWithRole(AdminRole::Support);
    $reader->givePermissionTo(AdminPermission::SafetyView->value);

    actingAsAdmin($reader);

    Livewire::test(SafetyCases::class)
        ->assertOk()
        ->assertSee(__('admin.queue.view_only'))
        ->call('acknowledge', $sos->id)
        ->assertForbidden();

    Livewire::test(SafetyCases::class)->call('take', $report->id)->assertForbidden();

    expect($sos->refresh()->first_touch_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The banner on every other page
|--------------------------------------------------------------------------
*/

it('puts an unanswered alert across the top of every other page the desk opens', function () {
    raiseSos($this->member);

    $super = adminWithRole(AdminRole::SuperAdmin);
    actingAsAdmin($super);

    $this->get(route('admin.verifications'))
        ->assertOk()
        ->assertSee(__('admin.safety.open'));
});

it('takes the banner down once somebody has picked the alert up', function () {
    $sos = raiseSos($this->member);

    $super = adminWithRole(AdminRole::SuperAdmin);
    actingAsAdmin($super);

    Livewire::test(SafetyCases::class)->call('acknowledge', $sos->id);

    $this->get(route('admin.verifications'))
        ->assertOk()
        ->assertDontSee(__('admin.safety.open'));
});

it('does not show the banner to somebody who cannot open the safety desk', function () {
    raiseSos($this->member);

    actingAsAdmin(adminWithRole(AdminRole::Verification));

    $this->get(route('admin.verifications'))
        ->assertOk()
        ->assertDontSee(__('admin.safety.open'));

    // Same reasoning as the queue counts: even a number is information about a queue.
    Livewire::test(VerificationQueue::class)->assertOk();
});
