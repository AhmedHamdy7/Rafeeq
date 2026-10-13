<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPreference>
 */
class UserPreferenceFactory extends Factory
{
    protected $model = UserPreference::class;

    /**
     * Everything optional defaults to null, which is the honest starting state: null means
     * "never asked", and a factory that invented an answer would hide every bug where the app
     * forgets to ask.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'last_active_mode' => null,
            'onboarding_seen_at' => null,
            'driver_walkthrough_seen_at' => null,
            'language' => null,
            'theme' => null,
        ];
    }

    public function drivingMode(): static
    {
        return $this->state(fn (): array => [
            'last_active_mode' => Capability::Driver,
            'driver_walkthrough_seen_at' => now(),
        ]);
    }
}
