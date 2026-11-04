<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleRejectedItem;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Support\Facades\DB;

final readonly class ClearVehicleRejectionAction
{
    public function execute(DriverProfile $profile, Vehicle $vehicle, VehicleRejectedItem $replaced): void
    {
        if ($vehicle->verification_status !== VehicleVerificationStatus::Rejected || $vehicle->rejected_item !== $replaced) {
            return;
        }

        DB::transaction(function () use ($profile, $vehicle): void {
            $vehicle->forceFill([
                'verification_status' => VehicleVerificationStatus::Pending->value,
                'rejected_item' => null,
                'rejection_reason' => null,
            ])->save();

            $stillRejected = $profile->vehicles()
                ->where('verification_status', VehicleVerificationStatus::Rejected->value)
                ->exists();

            if ($profile->status === DriverProfileStatus::Rejected && ! $stillRejected) {
                $profile->status = DriverProfileStatus::Draft->value;
                $profile->rejection_reason = null;
                $profile->save();
            }
        });
    }
}
