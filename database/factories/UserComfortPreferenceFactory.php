<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\ComfortPreference;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserComfortPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserComfortPreference>
 */
class UserComfortPreferenceFactory extends Factory
{
    protected $model = UserComfortPreference::class;

    /**
     * Every answer defaults to `either`, which is what a real untouched row holds. A factory that
     * randomised them would make any future matching test pass or fail by luck.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'mode' => Capability::Passenger,
            'smoking' => ComfortPreference::Either,
            'music' => ComfortPreference::Either,
            'conversation' => ComfortPreference::Either,
            'air_conditioning' => ComfortPreference::Either,
        ];
    }

    public function driving(): static
    {
        return $this->state(fn (): array => ['mode' => Capability::Driver]);
    }
}
