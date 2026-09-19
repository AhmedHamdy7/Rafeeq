<?php

namespace Database\Factories;

use App\Domains\Verification\Enums\DocumentKind;
use App\Domains\Verification\Enums\VirusScanStatus;
use App\Domains\Verification\Models\IdentityDocument;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdentityDocument>
 */
class IdentityDocumentFactory extends Factory
{
    protected $model = IdentityDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::ulid();

        return [
            'user_verification_id' => UserVerification::factory(),
            'kind' => DocumentKind::NationalIdFront,
            'file_path' => "private/id/{$id}/front.jpg",
            'file_hash' => hash('sha256', $id),
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(50_000, 3_000_000),
            'virus_scan_status' => VirusScanStatus::Clean,
        ];
    }
}
