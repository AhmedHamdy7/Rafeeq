<?php

namespace Database\Factories;

use App\Domains\Trip\Models\TripLocation;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripLocation>
 */
class TripLocationFactory extends Factory
{
    protected $model = TripLocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_session_id' => TripSession::factory(),
            'lat' => fake()->latitude(29.8, 30.2),
            'lng' => fake()->longitude(31.0, 31.6),
            'accuracy_meters' => fake()->numberBetween(3, 20),
            'speed_kmh' => fake()->numberBetween(0, 80),
            'recorded_at' => now(),
        ];
    }
}
