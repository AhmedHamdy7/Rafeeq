<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Identity\Models\User;
use App\Domains\Verification\Support\DocumentIntake;
use App\Domains\Verification\Support\DocumentStorage;
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
        DriverApplicationState::assertEditable($profile->status);

        $stored = $this->intake->accept($owner, $file, auditContext: [
            'vehicle_id' => $vehicle->id,
            'document_type' => $type->value,
        ]);

        return DB::transaction(function () use ($vehicle, $type, $stored, $expiresAt): VehicleDocument {
            // One document per type: re-uploading a blurry registration
            // replaces it rather than leaving a reviewer two to choose from.
            $this->replaceExisting($vehicle, $type);

            $document = new VehicleDocument;

            $document->fill([
                'vehicle_id' => $vehicle->id,
                'type' => $type->value,
                'file_path' => $stored['path'],
                'file_hash' => $stored['hash'],
                'expires_at' => $expiresAt,
                // §18 retention: set at upload so a purge job never has to
                // infer it.
                'purge_after' => now()->addDays(90)->toDateString(),
            ]);

            $document->save();

            return $document;
        });
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
