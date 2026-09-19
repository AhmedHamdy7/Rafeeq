<?php

namespace Database\Factories;

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripSession>
 */
class TripSessionFactory extends Factory
{
    protected $model = TripSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheduled_trip_id' => ScheduledTrip::factory(),
            'current_status' => TripSessionStatus::Preparing,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'started_at' => now()->subMinutes(10),
            'current_status' => TripSessionStatus::InProgress,
            'last_location_at' => now(),
        ]);
    }
}
