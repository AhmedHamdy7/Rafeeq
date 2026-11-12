<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
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

    public function render()
    {
        return view('livewire.admin.directory.commutes', [
            'commutes' => CommuteOffer::query()
                ->with(['driverProfile.user', 'vehicle', 'locations'])
                ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
                ->latest('created_at')
                ->latest('id')
                ->paginate(25),
            'tabs' => $this->statuses(),
        ])->title(__('admin/directory.commutes.title'));
    }
}
