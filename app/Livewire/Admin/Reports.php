<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\SafetyCaseQueue;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\Incident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

/**
 * REPORTS AND DISPUTES (R): the open reports on a page of their own.
 *
 * The same queue, the same decisions and the same permission checks as the safety desk —
 * it IS the safety desk's component, drawn without the live alerts — so a report taken or
 * resolved here is taken or resolved there, and nothing about a decision can differ
 * between the two pages.
 */
#[Layout('components.layouts.admin')]
class Reports extends SafetyCases
{
    /** R01 the open queue, or R06 the closed history. */
    #[Url(as: 'view')]
    public string $view = 'open';

    #[Url(as: 'q')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage('closed');
    }

    public function render()
    {
        $reports = SafetyCaseQueue::openReports(10);
        $search = trim($this->search);
        $like = '%'.addcslashes($search, '%_\\').'%';

        // R06: closed reports, newest decision first, searchable by either person's name or by category.
        $closed = $this->view === 'closed'
            ? Incident::query()
                ->whereIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Closed->value])
                ->with(['reporter', 'reportedUser'])
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                    ->whereHas('reporter', fn (Builder $user) => $user->where('full_name', 'like', $like))
                    ->orWhereHas('reportedUser', fn (Builder $user) => $user->where('full_name', 'like', $like))
                    ->orWhere('category', 'like', $like)
                    ->orWhere('resolution', 'like', $like)))
                ->orderByDesc('resolved_at')
                ->orderByDesc('id')
                ->paginate(20, pageName: 'closed')
            : null;

        return view('livewire.admin.reports', [
            'closed' => $closed,
            'reports' => $reports,
            'mayAct' => Auth::guard('admin')->user()?->can(AdminPermission::SafetyResolve->value) === true,
        ])->title(__('admin/reports.title'));
    }
}
