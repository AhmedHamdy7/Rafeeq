<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;

/** SEAT REQUESTS (B02): every request for a seat, the newest first, by status. Read-only. */
#[Layout('components.layouts.admin')]
class SeatRequests extends DirectoryPage
{
    protected function permission(): AdminPermission
    {
        return AdminPermission::CommuteView;
    }

    protected function statuses(): array
    {
        return ['pending', 'approved', 'waitlisted', 'counteroffered', 'rejected', 'withdrawn', 'expired'];
    }

    protected function tableQuery(): Builder
    {
        return SeatRequest::query()
            ->with(['passenger', 'commuteOffer.driverProfile.user', 'commuteOffer.locations'])
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->latest('created_at')
            ->latest('id');
    }

    protected function exportColumns(): array
    {
        return [
            'id' => fn (SeatRequest $request) => $request->id,
            'passenger' => fn (SeatRequest $request) => $request->passenger?->full_name,
            'driver' => fn (SeatRequest $request) => $request->commuteOffer?->driverProfile?->user?->full_name,
            'commitment' => fn (SeatRequest $request) => $request->commitment?->value,
            'seats' => fn (SeatRequest $request) => $request->seats,
            'status' => fn (SeatRequest $request) => $request->status instanceof SeatRequestStatus ? $request->status->value : (string) $request->status,
            'created_at' => fn (SeatRequest $request) => $request->created_at?->timezone('Africa/Cairo')->format('Y-m-d H:i'),
        ];
    }

    public function render()
    {
        return view('livewire.admin.directory.seat-requests', [
            'requests' => $this->tableQuery()->paginate(25),
            'tabs' => $this->statuses(),
            ...$this->tableTools(),
        ])->title(__('admin/directory.seat_requests.title'));
    }
}
