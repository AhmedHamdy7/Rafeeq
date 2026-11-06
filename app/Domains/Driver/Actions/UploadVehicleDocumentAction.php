<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Enums\VehicleRejectedItem;
use App\Domains\Driver\Enums\VehicleSuspensionReason;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Identity\Models\User;
use App\Domains\Verification\Support\DocumentIntake;
use App\Domains\Verification\Support\DocumentStorage;
use App\Domains\Verification\Support\VerificationSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Registration, insurance and inspection documents for a vehicle
 * (Chapter 3 §8).
 *
 * Goes through the same intake as identity documents — scanned, stripped of
 * metadata, then stored on the private disk. A vehicle registration carries the
 * owner's name and address, so it is no less sensitive than an ID.
 */
final readonly class UploadVehicleDocumentAction
{
    public function __construct(
        private DocumentIntake $intake,
        private DocumentStorage $storage,
        private ClearVehicleRejectionAction $clearRejection,
    ) {}

    /**
     * The profile is passed in rather than reached through `$vehicle->driverProfile`:
     * that relation would lazy-load, which `Model::preventLazyLoading()` turns
     * into an exception outside production — and the caller already has it.
     */
    public function execute(
        User $owner,
        DriverProfile $profile,
        Vehicle $vehicle,
        VehicleDocumentType $type,
        UploadedFile $file,
        ?string $expiresAt = null,
    ): VehicleDocument {
        $renewal = $this->isRenewal($profile, $vehicle, $type);

        if (! $renewal) {
            DriverApplicationState::assertEditable($profile->status);
        }

        $stored = $this->intake->accept($owner, $file, auditContext: [
            'vehicle_id' => $vehicle->id,
            'document_type' => $type->value,
        ]);

        return DB::transaction(function () use ($profile, $vehicle, $type, $stored, $expiresAt, $renewal): VehicleDocument {
            $previousExpiry = $vehicle->documents()->where('type', $type->value)->value('expires_at');

            // One document per type: re-uploading a blurry registration
            // replaces it rather than leaving a reviewer two to choose from.
            $this->replaceExisting($vehicle, $type);

            $document = new VehicleDocument;

            $document->fill([
                'vehicle_id' => $vehicle->id,
                'type' => $type->value,
                'file_path' => $stored['path'],
                'file_hash' => $stored['hash'],
                'expires_at' => $renewal ? $previousExpiry : $expiresAt,
                // §18 retention: set at upload so a purge job never has to
                // infer it.
                'purge_after' => now()->addDays(VerificationSettings::documentRetentionDays())->toDateString(),
            ]);

            $document->save();

            if ($renewal) {
                $vehicle->forceFill(['registration_renewal_submitted_at' => now(), 'rejection_reason' => null])->save();
            }

            if ($type->isRegistration()) {
                $this->clearRejection->execute($profile, $vehicle, VehicleRejectedItem::Registration);
            }

            return $document;
        });
    }

    private function isRenewal(DriverProfile $profile, Vehicle $vehicle, VehicleDocumentType $type): bool
    {
        return $type->isRegistration()
            && $profile->status === DriverProfileStatus::Approved
            && ($vehicle->verification_status === VehicleVerificationStatus::Approved
                || $vehicle->suspension_reason === VehicleSuspensionReason::RegistrationExpired->value);
    }

    private function replaceExisting(Vehicle $vehicle, VehicleDocumentType $type): void
    {
        $vehicle->documents()
            ->where('type', $type->value)
            ->get()
            ->each(function (VehicleDocument $stale): void {
                // Bytes first, then the row: the other order can leave a file
                // with no owner, which no purge job will ever find.
                $this->storage->disk()->delete($stale->file_path);
                $stale->delete();
            });
    }
}
