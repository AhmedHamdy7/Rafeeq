<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Safety\Models\Incident;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

/**
 * REPORT CASE (R02, P02): one report on its own page — what was reported, by whom, about whom,
 * every appeal round, the evidence (what arrived AFTER the first decision marked "new", so a
 * second look starts from what changed), and what staff did, in order. Decisions are the same
 * ones as on the queue (take, escalate, resolve, close, the money outcomes), reused from it.
 */
#[Layout('components.layouts.admin')]
class ReportDetail extends SafetyCases
{
    #[Locked]
    public string $incidentId = '';

    public function mount(?Incident $incident = null): void
    {
        parent::mount();

        abort_if($incident === null, 404);
        $this->incidentId = $incident->id;
    }

    public function render()
    {
        $report = Incident::query()
            ->whereKey($this->incidentId)
            ->with(['reporter', 'reportedUser', 'assignedAdmin', 'evidence', 'appeals', 'booking.scheduledTrip'])
            ->withCount('evidence')
            ->firstOrFail();

        $steps = AdminAction::query()->where('entity_id', $report->id)->with('admin')->orderBy('created_at')->orderBy('id')->get();

        // The first decision: evidence after it is what the appeal brought.
        $firstDecision = $steps->first(fn (AdminAction $step) => in_array($step->action, ['incident.resolve', 'incident.close'], true))?->created_at;

        $admin = Auth::guard('admin')->user();

        return view('livewire.admin.report-detail', [
            'report' => $report,
            // The queue's decision panel, for this one case.
            'reports' => new LengthAwarePaginator([$report], 1, 1),
            'steps' => $steps,
            'firstDecision' => $firstDecision,
            'mayAct' => $admin?->can(AdminPermission::SafetyResolve->value) === true && $report->isOpen(),
        ])->title(__('admin/reports.case_title'));
    }
}
