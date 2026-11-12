<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\HandleSupportTicketAction;
use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\SupportTicketStatus;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\SupportTicket;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * SUPPORT CASES (X): members' support cases by status, the most urgent and the closest
 * deadline first. Staff with ticket.view read; staff with ticket.resolve take, resolve and
 * close, each with a note in the audit log.
 */
#[Layout('components.layouts.admin')]
class Support extends Component
{
    use WithPagination;

    public const array TABS = ['open', 'in_progress', 'resolved', 'closed'];

    #[Url(as: 'status')]
    public string $status = 'open';

    public ?string $finishing = null;

    public string $finishAs = '';

    public string $note = '';

    public function mount(): void
    {
        abort_unless($this->admin()?->can(AdminPermission::TicketView->value) === true, 403);

        $this->status = in_array($this->status, self::TABS, true) ? $this->status : 'open';
    }

    public function pick(string $status): void
    {
        $this->status = in_array($status, self::TABS, true) ? $status : 'open';
        $this->reset('finishing', 'finishAs', 'note');
        $this->resetPage();
    }

    public function take(string $id, HandleSupportTicketAction $action): void
    {
        $this->authorizeToResolve();
        $action->take($this->ticket($id), $this->admin());
    }

    public function startFinish(string $id, string $as): void
    {
        $this->authorizeToResolve();
        $this->resetValidation();
        $this->finishing = $id;
        $this->finishAs = $as === 'closed' ? 'closed' : 'resolved';
        $this->note = '';
    }

    public function finish(HandleSupportTicketAction $action): void
    {
        $this->authorizeToResolve();

        $this->validate(['note' => ['required', 'string', 'min:5', 'max:1000']], attributes: ['note' => __('admin/support.note')]);

        $action->finish($this->ticket((string) $this->finishing), $this->admin(), SupportTicketStatus::from($this->finishAs), $this->note);

        $this->reset('finishing', 'finishAs', 'note');
    }

    private function ticket(string $id): SupportTicket
    {
        return SupportTicket::query()->whereKey($id)->first() ?? abort(404);
    }

    private function admin(): ?AdminUser
    {
        return Auth::guard('admin')->user();
    }

    private function authorizeToResolve(): void
    {
        abort_unless($this->admin()?->can(AdminPermission::TicketResolve->value) === true, 403);
    }

    public function render()
    {
        $counts = SupportTicket::query()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return view('livewire.admin.support', [
            'tickets' => SupportTicket::query()
                ->with(['user', 'assignedAdmin'])
                ->where('status', $this->status)
                ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
                ->orderByRaw('sla_due_at is null')
                ->orderBy('sla_due_at')
                ->orderBy('created_at')
                ->paginate(25),
            'counts' => $counts,
            'mayAct' => $this->admin()?->can(AdminPermission::TicketResolve->value) === true,
        ])->title(__('admin/support.title'));
    }
}
