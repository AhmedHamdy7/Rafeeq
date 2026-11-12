<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\SafetyCaseQueue;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;

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
    public function render()
    {
        $reports = SafetyCaseQueue::openReports(10);

        return view('livewire.admin.reports', [
            'reports' => $reports,
            'mayAct' => Auth::guard('admin')->user()?->can(AdminPermission::SafetyResolve->value) === true,
        ])->title(__('admin/reports.title'));
    }
}
