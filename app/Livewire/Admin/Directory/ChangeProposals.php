<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Commute\Enums\ChangeProposalStatus;
use App\Domains\Commute\Models\ChangeProposal;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;

/**
 * CHANGE PROPOSALS (B06): a driver asking riders to accept a new time or pickup, and how each
 * one ended. Read-only.
 */
#[Layout('components.layouts.admin')]
class ChangeProposals extends DirectoryPage
{
    protected function permission(): AdminPermission
    {
        return AdminPermission::CommuteView;
    }

    protected function statuses(): array
    {
        return array_map(fn (ChangeProposalStatus $status) => $status->value, ChangeProposalStatus::cases());
    }

    protected function tableQuery(): Builder
    {
        return ChangeProposal::query()
            ->with(['commuteOffer.driverProfile.user', 'commuteOffer.locations', 'scheduledTrip'])
            ->withCount('approvals')
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->latest('created_at')
            ->latest('id');
    }

    protected function exportColumns(): array
    {
        return [
            'id' => fn (ChangeProposal $proposal) => $proposal->id,
            'driver' => fn (ChangeProposal $proposal) => $proposal->commuteOffer?->driverProfile?->user?->full_name,
            'day' => fn (ChangeProposal $proposal) => $proposal->scheduledTrip?->trip_date?->format('Y-m-d'),
            'scope' => fn (ChangeProposal $proposal) => $proposal->scope?->value,
            'changes' => fn (ChangeProposal $proposal) => json_encode($proposal->changes, JSON_UNESCAPED_UNICODE),
            'answers' => fn (ChangeProposal $proposal) => $proposal->approvals_count,
            'status' => fn (ChangeProposal $proposal) => $proposal->status->value,
            'created_at' => fn (ChangeProposal $proposal) => $proposal->created_at?->timezone('Africa/Cairo')->format('Y-m-d H:i'),
        ];
    }

    public function render()
    {
        return view('livewire.admin.directory.change-proposals', [
            'proposals' => $this->tableQuery()->paginate(25),
            'tabs' => $this->statuses(),
            ...$this->tableTools(),
        ])->title(__('admin/directory.change_proposals.title'));
    }
}
