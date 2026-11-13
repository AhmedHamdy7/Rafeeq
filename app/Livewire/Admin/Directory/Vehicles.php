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

    protected function tableQuery(): Builder
    {
        $search = trim($this->search);
        $like = '%'.addcslashes($search, '%_\\').'%';

        return Vehicle::query()
            ->with('driverProfile.user')
            ->when($this->status !== 'all', fn (Builder $query) => $query->where('verification_status', $this->status))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('plate_number', 'like', $like)
                ->orWhereHas('driverProfile.user', fn (Builder $user) => $user->where('full_name', 'like', $like))))
            ->latest('created_at')
            ->latest('id');
    }

    protected function filters(): array
    {
        return ['status' => $this->status, 'q' => $this->search];
    }

    protected function applyFilters(array $filters): void
    {
        $this->search = (string) ($filters['q'] ?? '');
        parent::applyFilters($filters);
    }

    protected function exportColumns(): array
    {
        return [
            'id' => fn (Vehicle $vehicle) => $vehicle->id,
            'plate' => fn (Vehicle $vehicle) => $vehicle->plate_number,
            'car' => fn (Vehicle $vehicle) => trim($vehicle->make.' '.$vehicle->model),
            'colour' => fn (Vehicle $vehicle) => $vehicle->colour,
            'driver' => fn (Vehicle $vehicle) => $vehicle->driverProfile?->user?->full_name,
            'seats' => fn (Vehicle $vehicle) => $vehicle->approved_seats ?? $vehicle->seats,
            'status' => fn (Vehicle $vehicle) => $vehicle->verification_status instanceof \BackedEnum ? $vehicle->verification_status->value : $vehicle->verification_status,
            'created_at' => fn (Vehicle $vehicle) => $vehicle->created_at?->timezone('Africa/Cairo')->format('Y-m-d H:i'),
        ];
    }

    public function render()
    {
        return view('livewire.admin.directory.vehicles', [
            'vehicles' => $this->tableQuery()->paginate(25),
            'tabs' => $this->statuses(),
            ...$this->tableTools(),
        ])->title(__('admin/directory.vehicles.title'));
    }
}
