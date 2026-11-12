<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleRejectedItem;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Enums\VerificationLogAction;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VerificationLog;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

final readonly class ReviewReplacementVehicleAction
{
    public function __construct(private ReviewVehicleAction $review) {}

    public function approve(Vehicle $vehicle, AdminUser $reviewer, ?int $approvedSeats = null, VehicleTier $tier = VehicleTier::Comfort, ?bool $hasAc = null): Vehicle
    {
        $this->assertReviewable($vehicle);

        return DB::transaction(function () use ($vehicle, $reviewer, $approvedSeats, $tier, $hasAc): Vehicle {
            $seats = $approvedSeats ?? max(1, min((int) ($vehicle->seats ?? 4), (int) config('rafeeq.driver.max_approved_seats')));

            $this->review->execute($vehicle, $reviewer, $seats, $tier, $hasAc ?? (bool) $vehicle->has_ac);

            $previous = $vehicle->verification_status;

            $vehicle->forceFill([
                'verification_status' => VehicleVerificationStatus::Approved->value,
                'rejected_item' => null,
                'rejection_reason' => null,
            ])->save();

            $this->log($vehicle, $reviewer, VerificationLogAction::Approve, $previous, null);
            $this->tell($vehicle, NotificationType::DriverItemApproved, VehicleRejectedItem::Details);

            return $vehicle->refresh();
        });
    }

    public function reject(Vehicle $vehicle, AdminUser $reviewer, VehicleRejectedItem $item, string $reason): Vehicle
    {
        $this->assertReviewable($vehicle);

        if (trim($reason) === '') {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'reason' => [__('validation.required', ['attribute' => 'reason'])],
            ]);
        }

        return DB::transaction(function () use ($vehicle, $reviewer, $item, $reason): Vehicle {
            $previous = $vehicle->verification_status;

            $vehicle->forceFill([
                'verification_status' => VehicleVerificationStatus::Rejected->value,
                'rejected_item' => $item->value,
                'rejection_reason' => $reason,
            ])->save();

            $this->log($vehicle, $reviewer, VerificationLogAction::Reject, $previous, $reason);
            $this->tell($vehicle, NotificationType::DriverItemRejected, $item, ['rejectionReason' => $reason]);

            return $vehicle->refresh();
        });
    }

    private function assertReviewable(Vehicle $vehicle): void
    {
        $vehicle->loadMissing('driverProfile');

        if ($vehicle->driverProfile?->status !== DriverProfileStatus::Approved) {
            throw DomainException::of(ErrorCode::VehicleNotAllowed, fields: [
                'driverStatus' => [strtoupper((string) $vehicle->driverProfile?->status?->value)],
            ]);
        }

        if ($vehicle->verification_status !== null && $vehicle->verification_status !== VehicleVerificationStatus::Pending) {
            throw DomainException::of(ErrorCode::VehicleNotAllowed, fields: [
                'verificationStatus' => [strtoupper($vehicle->verification_status->value)],
            ]);
        }
    }

    private function log(Vehicle $vehicle, AdminUser $reviewer, VerificationLogAction $action, ?VehicleVerificationStatus $previous, ?string $reason): void
    {
        VerificationLog::create([
            'entity_type' => 'vehicle',
            'entity_id' => $vehicle->id,
            'admin_id' => $reviewer->id,
            'action' => $action->value,
            'old_value' => ['verification_status' => $previous?->value],
            'new_value' => ['verification_status' => $vehicle->verification_status?->value],
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function tell(Vehicle $vehicle, NotificationType $type, VehicleRejectedItem $item, array $extra = []): void
    {
        $user = $vehicle->driverProfile?->user;

        if ($user === null) {
            return;
        }

        $label = __('notifications.driver_items.'.$item->value, [], $user->preferred_language ?: config('app.locale'));

        Notifier::send($user, $type, params: ['item' => $label], data: [
            'itemKey' => $item->value,
            'vehicleId' => (string) $vehicle->id,
            ...$extra,
        ]);
    }
}
