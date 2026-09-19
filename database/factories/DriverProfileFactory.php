<?php

namespace Database\Factories;

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverProfile>
 */
class DriverProfileFactory extends Factory
{
    protected $model = DriverProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => DriverProfileStatus::PendingReview,
            'national_id' => (string) fake()->numberBetween(29000000000000, 29999999999999),
            'licence_number' => (string) fake()->numberBetween(100000, 999999),
            'licence_expiry' => now()->addYears(3),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DriverProfileStatus::Approved,
            'verified_at' => now(),
        ]);
    }

    public function withExpiredLicence(): static
    {
        return $this->state(fn (array $attributes) => [
            'licence_expiry' => now()->subMonth(),
        ]);
    }
}
