<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserStat>
 */
class UserStatFactory extends Factory
{
    protected $model = UserStat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'completed_trips_as_passenger' => fake()->numberBetween(0, 100),
            'completed_trips_as_driver' => 0,
            'avg_rating_as_passenger' => fake()->randomFloat(2, 4, 5),
            'on_time_rate' => fake()->randomFloat(2, 90, 100),
            'cancellation_rate' => fake()->randomFloat(2, 0, 5),
            'no_show_count' => 0,
            'computed_at' => now(),
        ];
    }
}
