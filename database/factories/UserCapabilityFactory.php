<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\CapabilityStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserCapability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserCapability>
 */
class UserCapabilityFactory extends Factory
{
    protected $model = UserCapability::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'capability' => Capability::Passenger,
            'status' => CapabilityStatus::Active,
            'enrolled_at' => now(),
            'withdrawn_at' => null,
        ];
    }

    /**
     * 🔴 `capability` is NOT randomised in `definition()`, and this state exists instead.
     *
     * A random pick would collide with the `(user_id, capability)` unique index whenever a test
     * built two capabilities for one person — intermittently, which is the worst way for a test
     * to fail. Naming the capability makes every test say which one it means.
     */
    public function driver(): static
    {
        return $this->state(fn (): array => ['capability' => Capability::Driver]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (): array => [
            'status' => CapabilityStatus::Withdrawn,
            'withdrawn_at' => now(),
        ]);
    }
}
