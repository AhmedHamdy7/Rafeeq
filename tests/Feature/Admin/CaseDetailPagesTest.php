<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\SupportTicket;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\IncidentAppeal;
use App\Domains\Safety\Models\IncidentEvidence;
use App\Livewire\Admin\ReportDetail;
use App\Livewire\Admin\SupportCase;
use Livewire\Livewire;

/**
 * Report case with appeal rounds and new evidence (R02, P02), and the support case (X02).
 */
it('opens a report case and marks evidence sent after the first decision as new', function () {
    $lead = adminWithRole(AdminRole::SafetyLead);
    $report = Incident::factory()->create();
    IncidentEvidence::factory()->create(['incident_id' => $report->id, 'created_at' => now()->subDays(2)]);
    AdminActionLog::record($lead, 'incident.resolve', $report, null, null, 'Warned the driver');
    $this->travel(1)->hours();
    IncidentEvidence::factory()->create(['incident_id' => $report->id]);
    IncidentAppeal::factory()->create(['incident_id' => $report->id, 'round' => 1]);
    $report->forceFill(['status' => IncidentStatus::Appealed->value])->save();
    actingAsAdmin($lead);

    $this->get(route('admin.reports.show', $report))
        ->assertOk()
        ->assertSee(__('admin/reports.new_evidence'))
        ->assertSee(__('admin.safety.appeal_round', ['round' => 1]));
});

it('decides a case from its own page', function () {
    $report = Incident::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));

    Livewire::test(ReportDetail::class, ['incident' => $report])
        ->call('openDecision', $report->id, 'close')
        ->set('reply', 'Not enough to act on, thank you for telling us')
        ->call('decide', $report->id)
        ->assertHasNoErrors();

    expect($report->refresh()->status)->toBe(IncidentStatus::Closed);
});

it('keeps report cases from staff without the safety desk', function () {
    $report = Incident::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    $this->get(route('admin.reports.show', $report))->assertForbidden();
});

it('opens a support case and takes it', function () {
    $ticket = SupportTicket::factory()->create();
    $admin = adminWithRole(AdminRole::Support);
    actingAsAdmin($admin);

    $this->get(route('admin.support.show', $ticket))->assertOk()->assertSee(__('admin/support.opened_event'));

    Livewire::test(SupportCase::class, ['ticket' => $ticket])->call('take', $ticket->id)->assertHasNoErrors();

    expect($ticket->refresh()->assigned_admin_id)->toBe($admin->id);
});

it('has the case strings in both languages', function () {
    expect(array_keys(trans('admin/support', [], 'ar')))->toEqualCanonicalizing(array_keys(trans('admin/support', [], 'en')))
        ->and(array_keys(trans('admin/reports', [], 'ar')))->toEqualCanonicalizing(array_keys(trans('admin/reports', [], 'en')));
});
