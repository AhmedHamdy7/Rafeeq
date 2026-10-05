<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The AUDIT LOG section: what staff did, newest first. Read-only, like the table it reads.
 *
 * `audit_log.view` — the super admin by default. The log names who decided what about whom,
 * which is exactly why the people it records should not all be able to read it.
 *
 * 🔒 What is NOT shown: the IP and user-agent hashes. They exist to compare ("same browser
 * as last time?") and are meaningless to a reader; printing them would only make a
 * screenshot of this page carry them somewhere else.
 *
 * Filter by area (the part of the action before the dot), by staff member, and by the id of
 * the thing acted on — the last is what answers "what happened to this case / this person".
 */
#[Layout('components.layouts.admin')]
class AuditLog extends Component
{
    use WithPagination;

    public const array AREAS = ['admin', 'verification', 'driver', 'sos', 'incident', 'account', 'settings'];

    #[Url(as: 'area')]
    public string $area = '';

    #[Url(as: 'by')]
    public string $adminId = '';

    #[Url(as: 'subject')]
    public string $subject = '';

    public function mount(): void
    {
        abort_unless(Auth::guard('admin')->user()?->can(AdminPermission::AuditLogView->value) === true, 403);
    }

    // A narrowed filter starts from page one, or it can land on an empty page past the end.
    public function updatedArea(): void
    {
        $this->resetPage();
    }

    public function updatedAdminId(): void
    {
        $this->resetPage();
    }

    public function updatedSubject(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $entries = AdminAction::query()
            ->with('admin')
            ->when(in_array($this->area, self::AREAS, true), fn (Builder $query) => $query->where('action', 'like', $this->area.'.%'))
            ->when($this->adminId !== '', fn (Builder $query) => $query->where('admin_id', $this->adminId))
            ->when(trim($this->subject) !== '', fn (Builder $query) => $query->where('entity_id', trim($this->subject)))
            // Same-second rows are common here (an approval and its audit row), so the id
            // breaks the tie — standard #42.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.admin.audit-log', [
            'entries' => $entries,
            'staff' => AdminUser::query()->orderBy('name')->get(['id', 'name']),
        ])->title(__('admin.audit.title'));
    }
}
