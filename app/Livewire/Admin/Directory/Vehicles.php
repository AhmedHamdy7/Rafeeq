<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

/**
 * VEHICLES (E): every car on the platform, by state, searchable by plate or driver.
 * Read-only: approving, rejecting and reinstating cars stay on Driver verification and
 * Replacement cars, where the papers are.
 */
#[Layout('components.layouts.admin')]
class Vehicles extends DirectoryPage
{
    #[Url(as: 'q')]
    public string $search = '';

    protected function permission(): AdminPermission
    {
        return AdminPermission::DriverView;
    }

    protected function statuses(): array
    {
        return array_map(fn (VehicleVerificationStatus $status) => $status->value, VehicleVerificationStatus::cases());
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $search = trim($this->search);
        $like = '%'.addcslashes($search, '%_\\').'%';

        return view('livewire.admin.directory.vehicles', [
            'vehicles' => Vehicle::query()
                ->with('driverProfile.user')
                ->when($this->status !== 'all', fn (Builder $query) => $query->where('verification_status', $this->status))
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                    ->where('plate_number', 'like', $like)
                    ->orWhereHas('driverProfile.user', fn (Builder $user) => $user->where('full_name', 'like', $like))))
                ->latest('created_at')
                ->latest('id')
                ->paginate(25),
            'tabs' => $this->statuses(),
        ])->title(__('admin/directory.vehicles.title'));
    }
}
