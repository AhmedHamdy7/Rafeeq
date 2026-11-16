<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Actions\ReReviewVehicleAction;
use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Actions\VehicleSuspensionAction;
use App\Domains\Driver\Enums\VehicleSuspensionReason;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\Exceptions\DomainException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

/**
 * VEHICLE DETAIL (E03–E05, E07): the car, its papers and their dates, the commutes it runs,
 * and what staff did to it. Driver reviewers suspend it after a report (its commutes pause),
 * reinstate it, and approve or refuse a renewed registration. Customer Service read only.
 */
#[Layout('components.layouts.admin')]
class VehicleDetail extends RecordPage
{
    #[Locked]
    public string $vehicleId = '';

    public string $renewalDate = '';

    public ?string $acting = null;

    /** E06 · the re-review's proposed values. */
    public string $reSeats = '';

    public string $reTier = 'standard';

    public bool $reAc = false;

    public function mount(Vehicle $vehicle): void
    {
        $this->authorizeTo(AdminPermission::DriverView);
        $this->vehicleId = $vehicle->id;
    }

    public function start(string $action): void
    {
        $this->authorizeTo(AdminPermission::DriverDecide);
        $this->resetValidation();
        $this->reset('reason', 'renewalDate', 'done');
        $this->acting = in_array($action, ['suspend', 'reinstate', 'approve_renewal', 'reject_renewal', 're_review'], true) ? $action : null;

        if ($this->acting === 're_review') {
            $vehicle = $this->vehicle();
            $this->reSeats = (string) ($vehicle->approved_seats ?? $vehicle->seats);
            $this->reTier = $vehicle->tierOrDefault()->value;
            $this->reAc = (bool) $vehicle->has_ac;
        }
    }

    public function act(VehicleSuspensionAction $cars, ReReviewVehicleAction $reviews): void
    {
        $this->authorizeTo(AdminPermission::DriverDecide);
        $vehicle = $this->vehicle();
        $admin = $this->admin();

        match ($this->acting) {
            'suspend' => (function () use ($cars, $vehicle, $admin) {
                abort_unless($vehicle->verification_status === VehicleVerificationStatus::Approved, 409);
                $this->requireReason();
                $cars->suspend($vehicle, VehicleSuspensionReason::Report, $admin, $this->reason);
            })(),
            'reinstate' => (function () use ($cars, $vehicle, $admin) {
                // Only a suspension staff made: an expired registration is lifted by approving its renewal.
                abort_unless($vehicle->verification_status === VehicleVerificationStatus::Suspended && $vehicle->suspension_reason === VehicleSuspensionReason::Report->value, 409);
                $cars->reinstate($vehicle, $admin);
            })(),
            'approve_renewal' => (function () use ($cars, $vehicle, $admin) {
                $this->validate(['renewalDate' => ['required', 'date', 'after:today']], attributes: ['renewalDate' => __('admin/records.vehicle.new_expiry')]);
                $cars->approveRenewal($vehicle, $admin, $this->renewalDate);
            })(),
            'reject_renewal' => (function () use ($cars, $vehicle, $admin) {
                $this->validate(['reason' => ['required', 'string', 'min:10', 'max:255']], attributes: ['reason' => __('admin/actions.reason')]);
                $cars->rejectRenewal($vehicle, $admin, $this->reason);
            })(),
            're_review' => (function () use ($reviews, $vehicle, $admin) {
                $this->validate([
                    'reSeats' => ['required', 'integer', 'min:1', 'max:8'],
                    'reTier' => ['required', 'string', Rule::enum(VehicleTier::class)],
                    'reason' => ['required', 'string', 'min:10', 'max:255'],
                ], attributes: ['reSeats' => __('admin/records.vehicle.approved_seats'), 'reason' => __('admin/actions.reason')]);

                try {
                    $reviews->execute($admin, $vehicle, (int) $this->reSeats, VehicleTier::from($this->reTier), $this->reAc, $this->reason);
                } catch (DomainException $e) {
                    throw ValidationException::withMessages(['reSeats' => $e->fields['approvedSeats'][0] ?? __('errors.'.$e->errorCode->value)]);
                }
            })(),
            default => abort(404),
        };

        $this->done = __('admin/records.vehicle.done.'.$this->acting);
        $this->reset('acting', 'reason', 'renewalDate');
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::query()->whereKey($this->vehicleId)->firstOrFail();
    }

    public function render()
    {
        $vehicle = Vehicle::query()->whereKey($this->vehicleId)->with(['driverProfile.user', 'documents'])->firstOrFail();

        return view('livewire.admin.directory.vehicle-detail', [
            'vehicle' => $vehicle,
            // E06: what each commute would charge after the re-review, before it is saved.
            'rePreview' => $this->acting === 're_review' && is_numeric($this->reSeats) && VehicleTier::tryFrom($this->reTier) !== null
                ? ReReviewVehicleAction::preview($vehicle, max(1, (int) $this->reSeats), VehicleTier::from($this->reTier))
                : [],
            'commutes' => CommuteOffer::query()->where('vehicle_id', $vehicle->id)->with('locations')->latest('created_at')->limit(20)->get(),
            'history' => $this->staffActions($vehicle->id),
            'mayAct' => $this->may(AdminPermission::DriverDecide),
        ])->title((string) $vehicle->plate_number);
    }
}
