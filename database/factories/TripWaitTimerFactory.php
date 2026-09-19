<?php

namespace Database\Factories;

use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\Models\TripWaitTimer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripWaitTimer>
 */
class TripWaitTimerFactory extends Factory
{
    protected $model = TripWaitTimer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_session_id' => TripSession::factory(),
            'booking_id' => Booking::factory(),
            'started_at' => now(),
            'grace_seconds' => 300,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'started_at' => now()->subMinutes(10),
            'outcome' => 'no_show',
        ]);
    }
}
