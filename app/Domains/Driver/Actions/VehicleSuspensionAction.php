<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Enums\VehicleSuspensionReason;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Enums\VerificationLogAction;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Driver\Models\VerificationLog;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class VehicleSuspensionAction
{
    public function __construct(private ChangeCommuteStatusAction $commutes) {}

    public function sweepExpiredRegistrations(): int
    {
        $stopped = 0;

        Vehicle::query()
            ->where('verification_status', VehicleVerificationStatus::Approved->value)
            ->whereHas('documents', fn ($query) => $query
                ->whereIn('type', VehicleDocument::REGISTRATION_TYPES)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()))
            ->with('driverProfile.user')
            ->orderBy('id')
            ->chunkById(200, function ($vehicles) use (&$stopped): void {
                foreach ($vehicles as $vehicle) {
                    try {
                        $this->suspend($vehicle, VehicleSuspensionReason::RegistrationExpired);
                        $stopped++;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        return $stopped;
    }

    public function suspend(Vehicle $vehicle, VehicleSuspensionReason $reason, ?AdminUser $admin = null, ?string $note = null): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $reason, $admin, $note): Vehicle {
            $vehicle->forceFill([
                'verification_status' => VehicleVerificationStatus::Suspended->value,
                'suspension_reason' => $reason->value,
            ])->save();

            CommuteOffer::query()
                ->where('vehicle_id', $vehicle->getKey())
                ->where('status', CommuteOfferStatus::Published->value)
                ->get()
                ->each(fn (CommuteOffer $offer) => $this->commutes->pause($offer, CommutePausedReason::VehicleSuspended));

            $this->log($vehicle, $admin, $reason->value, $note);

            $vehicle->loadMissing('driverProfile.user');

            $driver = $vehicle->driverProfile?->user;

            if ($driver !== null) {
                Notifier::send($driver, $reason === VehicleSuspensionReason::RegistrationExpired
                    ? NotificationType::VehicleRegistrationExpired
                    : NotificationType::VehicleSuspended, data: [
                        'vehicleId' => (string) $vehicle->getKey(),
                        'itemKey' => 'vehicle_registration',
                    ]);
            }

            return $vehicle;
        });
    }

    public function reinstate(Vehicle $vehicle, AdminUser $admin): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $admin): Vehicle {
            $vehicle->forceFill([
                'verification_status' => VehicleVerificationStatus::Approved->value,
                'suspension_reason' => null,
                'is_active' => true,
            ])->save();

            $this->log($vehicle, $admin, 'reinstated', null);
            $this->tellReinstated($vehicle);

            return $vehicle;
        });
    }

    public function approveRenewal(Vehicle $vehicle, AdminUser $admin, string $expiresAt): Vehicle
    {
        $date = CarbonImmutable::parse($expiresAt);

        if (! $date->isFuture()) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: ['expiresAt' => [__('validation.after', ['attribute' => 'expiresAt', 'date' => 'today'])]]);
        }

        return DB::transaction(function () use ($vehicle, $admin, $date): Vehicle {
            $vehicle->documents()
                ->whereIn('type', VehicleDocument::REGISTRATION_TYPES)
                ->update(['expires_at' => $date, 'expiry_warned_for' => null]);

            $wasSuspended = $vehicle->verification_status === VehicleVerificationStatus::Suspended;

            $vehicle->forceFill([
                'verification_status' => VehicleVerificationStatus::Approved->value,
                'suspension_reason' => null,
                'registration_renewal_submitted_at' => null,
            ])->save();

            $this->log($vehicle, $admin, 'renewal_approved', $date->toDateString());

            if ($wasSuspended) {
                $this->tellReinstated($vehicle);
            }

            return $vehicle;
        });
    }

    public function rejectRenewal(Vehicle $vehicle, AdminUser $admin, string $reason): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $admin, $reason): Vehicle {
            $vehicle->forceFill([
                'registration_renewal_submitted_at' => null,
                'rejection_reason' => $reason,
            ])->save();

            $this->log($vehicle, $admin, 'renewal_rejected', $reason);

            return $vehicle;
        });
    }

    private function tellReinstated(Vehicle $vehicle): void
    {
        $vehicle->loadMissing('driverProfile.user');

        $driver = $vehicle->driverProfile?->user;

        if ($driver !== null) {
            Notifier::send($driver, NotificationType::VehicleReinstated, data: ['vehicleId' => (string) $vehicle->getKey()]);
        }
    }

    private function log(Vehicle $vehicle, ?AdminUser $admin, string $what, ?string $note): void
    {
        if ($admin === null) {
            return;
        }

        VerificationLog::create([
            'entity_type' => 'vehicle',
            'entity_id' => $vehicle->id,
            'admin_id' => $admin->id,
            'action' => VerificationLogAction::ReviewCar->value,
            'old_value' => null,
            'new_value' => ['event' => $what],
            'reason' => $note,
        ]);
    }
}
