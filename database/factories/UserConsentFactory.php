<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\ConsentDocumentType;
use App\Domains\Identity\Enums\ConsentSource;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserConsent>
 */
class UserConsentFactory extends Factory
{
    protected $model = UserConsent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'document_type' => ConsentDocumentType::Terms,
            'document_version' => '2026-08-01',
            'accepted_at' => now(),
            'source' => ConsentSource::Registration,
        ];
    }
}
