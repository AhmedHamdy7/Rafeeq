<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Driver\Actions\ReviewReplacementVehicleAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleRejectedItem;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Support\VehicleSettings;
use App\Domains\Shared\Exceptions\DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class ReplacementCars extends Component
{
    use WithPagination;

    /**
     * @var array<string, array{approvedSeats?: mixed, tier?: string, hasAc?: bool}>
     */
    public array $cars = [];

    public ?string $rejectingId = null;

    public ?string $rejectionItem = null;

    public string $reason = '';

    public function mount(): void
    {
        $this->authorizeTo(AdminPermission::DriverView);
    }

    public function approve(string $vehicleId, ReviewReplacementVehicleAction $action): void
    {
        $this->authorizeTo(AdminPermission::DriverDecide);

        $vehicle = $this->waiting($vehicleId);
        $this->resetErrorBag("cars.{$vehicle->id}");
        $car = $this->cars[$vehicle->id] ?? [];
        $seats = $car['approvedSeats'] ?? null;

        if (! is_numeric($seats)) {
            $this->addError("cars.{$vehicle->id}", __('admin.replacement_cars.seats_required'));

            return;
        }

        $tier = VehicleTier::tryFrom((string) ($car['tier'] ?? '')) ?? VehicleTier::Standard;
        $hasAc = (bool) ($car['hasAc'] ?? false);
        $admin = Auth::guard('admin')->user();

        try {
            $before = ['verification_status' => $vehicle->verification_status?->value];

            $action->approve($vehicle, $admin, (int) $seats, $tier, $hasAc);

            $fresh = $vehicle->fresh();

            AdminActionLog::record(
                $admin,
                'vehicle.approve_replacement',
                $vehicle,
                before: $before,
                after: [
                    'verification_status' => $fresh->verification_status->value,
                    'approved_seats' => $fresh->approved_seats,
                    'tier' => $fresh->tierOrDefault()->value,
                    'has_ac' => (bool) $fresh->has_ac,
                ],
            );
        } catch (DomainException $e) {
            $this->addError("cars.{$vehicle->id}", $e->fields['approvedSeats'][0] ?? __('errors.'.$e->errorCode->value));

            return;
        }

        unset($this->cars[$vehicle->id]);

        $this->done(__('admin.replacement_cars.approved', [
            'car' => trim("{$vehicle->make} {$vehicle->model}"),
            'name' => $vehicle->driverProfile->user->full_name,
        ]));
    }

    public function openRejection(string $vehicleId, string $item): void
    {
        $this->authorizeTo(AdminPermission::DriverDecide);

        $this->rejectingId = $vehicleId;
        $this->rejectionItem = $item;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function closeRejection(): void
    {
        $this->rejectingId = null;
        $this->rejectionItem = null;
        $this->reason = '';
    }

    public function reject(ReviewReplacementVehicleAction $action): void
    {
        $this->authorizeTo(AdminPermission::DriverDecide);

        $this->validate([
            'rejectingId' => ['required', 'string'],
            'rejectionItem' => ['required', Rule::enum(VehicleRejectedItem::class)],
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ], attributes: ['reason' => __('admin.queue.reason')]);

        $vehicle = $this->waiting((string) $this->rejectingId);
        $item = VehicleRejectedItem::from((string) $this->rejectionItem);
        $admin = Auth::guard('admin')->user();

        try {
            $before = ['verification_status' => $vehicle->verification_status?->value];

            $action->reject($vehicle, $admin, $item, $this->reason);

            AdminActionLog::record(
                $admin,
                'vehicle.reject_replacement',
                $vehicle,
                before: $before,
                after: [
                    'verification_status' => $vehicle->fresh()->verification_status->value,
                    'rejected_item' => $item->value,
                ],
                reason: $this->reason,
            );
        } catch (DomainException $e) {
            $this->addError('reason', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->closeRejection();

        $this->done(__('admin.replacement_cars.rejected', [
            'item' => __('notifications.driver_items.'.$item->value),
            'name' => $vehicle->driverProfile->user->full_name,
        ]));
    }

    private function waiting(string $vehicleId): Vehicle
    {
        $vehicle = $this->queue()->whereKey($vehicleId)->first();

        abort_if($vehicle === null, 404);

        return $vehicle;
    }

    /**
     * @return Builder<Vehicle>
     */
    private function queue(): Builder
    {
        return Vehicle::query()
            ->where('verification_status', VehicleVerificationStatus::Pending->value)
            ->whereHas('driverProfile', fn (Builder $query) => $query->where('status', DriverProfileStatus::Approved->value)->whereHas('user'))
            ->with(['driverProfile.user', 'driverProfile.vehicles', 'documents']);
    }

    private function done(string $message): void
    {
        session()->flash('status', $message);

        $page = $this->waitingPage();

        if ($page->currentPage() > $page->lastPage()) {
            $this->setPage($page->lastPage());
        }
    }

    /**
     * @return LengthAwarePaginator<int, Vehicle>
     */
    private function waitingPage()
    {
        return $this->queue()
            ->orderBy('updated_at')
            ->orderBy('id')
            ->paginate(10);
    }

    private function authorizeTo(AdminPermission $permission): void
    {
        abort_unless(
            Auth::guard('admin')->user()?->can($permission->value) === true,
            403,
        );
    }

    public function render()
    {
        $admin = Auth::guard('admin')->user();

        return view('livewire.admin.replacement-cars', [
            'vehicles' => $this->waitingPage(),
            'mayDecide' => $admin?->can(AdminPermission::DriverDecide->value) === true,
            'maySeeDocuments' => $admin?->can(AdminPermission::VerificationViewDocument->value) === true,
            'minApprovedSeats' => VehicleSettings::minApprovedSeats(),
            'maxApprovedSeats' => (int) config('rafeeq.driver.max_approved_seats'),
        ]);
    }
}
