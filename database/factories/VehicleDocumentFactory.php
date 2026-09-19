<?php

namespace Database\Factories;

use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VehicleDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VehicleDocument>
 */
class VehicleDocumentFactory extends Factory
{
    protected $model = VehicleDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::ulid();

        return [
            'vehicle_id' => Vehicle::factory(),
            'type' => VehicleDocumentType::Registration,
            'file_path' => "private/vehicles/{$id}/registration.pdf",
            'file_hash' => hash('sha256', $id),
            'expires_at' => now()->addYear(),
            'verification_status' => VehicleVerificationStatus::Approved,
        ];
    }
}
