<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;

/** COMMUTES (C): every driver's commute, newest first, by status. */
#[Layout('components.layouts.admin')]
class Commutes extends DirectoryPage
{
    protected function permission(): AdminPermission
    {
        return AdminPermission::CommuteView;
    }

    protected function statuses(): array
    {
        return array_map(fn (CommuteOfferStatus $status) => $status->value, CommuteOfferStatus::cases());
    }

    protected function tableQuery(): Builder
    {
        return CommuteOffer::query()
            ->with(['driverProfile.user', 'vehicle', 'locations'])
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->latest('created_at')
            ->latest('id');
    }

    protected function exportColumns(): array
    {
        $place = fn (CommuteOffer $commute, CommuteLocationType $type) => (string) $commute->locations->firstWhere('type', $type)?->address;

        return [
            'id' => fn (CommuteOffer $commute) => $commute->id,
            'from' => fn (CommuteOffer $commute) => $place($commute, CommuteLocationType::Origin),
            'to' => fn (CommuteOffer $commute) => $place($commute, CommuteLocationType::Destination),
            'driver' => fn (CommuteOffer $commute) => $commute->driverProfile?->user?->full_name,
            'car' => fn (CommuteOffer $commute) => trim($commute->vehicle?->make.' '.$commute->vehicle?->model),
            'seats' => fn (CommuteOffer $commute) => $commute->seats_total,
            'audience' => fn (CommuteOffer $commute) => $commute->audience?->value,
            'status' => fn (CommuteOffer $commute) => $commute->status->value,
            'created_at' => fn (CommuteOffer $commute) => $commute->created_at?->timezone('Africa/Cairo')->format('Y-m-d H:i'),
        ];
    }

    public function render()
    {
        return view('livewire.admin.directory.commutes', [
            'commutes' => $this->tableQuery()->paginate(25),
            'tabs' => $this->statuses(),
            ...$this->tableTools(),
        ])->title(__('admin/directory.commutes.title'));
    }
}
