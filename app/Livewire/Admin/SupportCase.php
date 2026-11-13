<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\MemberNote;
use App\Domains\Admin\Models\SupportTicket;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\Incident;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

/**
 * SUPPORT CASE (X02): one case — who it is about, why it was opened (the escalation note), what
 * else is going on with that member, and what staff did, in order. Open → In progress →
 * Resolved → Closed, with the same take / resolve / close as the list (reused from it).
 */
#[Layout('components.layouts.admin')]
class SupportCase extends Support
{
    #[Locked]
    public string $ticketId = '';

    public function mount(?SupportTicket $ticket = null): void
    {
        parent::mount();

        abort_if($ticket === null, 404);
        $this->ticketId = $ticket->id;
    }

    public function render()
    {
        $ticket = SupportTicket::query()->whereKey($this->ticketId)->with(['user', 'assignedAdmin'])->firstOrFail();

        return view('livewire.admin.support-case', [
            'ticket' => $ticket,
            'steps' => AdminAction::query()->where('entity_id', $ticket->id)->with('admin')->orderBy('created_at')->orderBy('id')->get(),
            // The note written when it was opened, and anything written about her since.
            'notes' => MemberNote::query()->where('user_id', $ticket->user_id)->where('created_at', '>=', $ticket->created_at->subMinute())->with('admin')->orderBy('created_at')->limit(20)->get(),
            'openReports' => Incident::query()
                ->where(fn ($q) => $q->where('reporter_user_id', $ticket->user_id)->orWhere('reported_user_id', $ticket->user_id))
                ->whereIn('status', IncidentStatus::openValues())
                ->latest('created_at')
                ->limit(10)
                ->get(),
            'mayAct' => auth('admin')->user()?->can('ticket.resolve') === true,
        ])->title(__('admin/support.case_title'));
    }
}
