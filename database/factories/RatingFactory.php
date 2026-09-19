<?php

namespace Database\Factories;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Rating\Enums\RatingDirection;
use App\Domains\Rating\Models\Rating;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rating>
 */
class RatingFactory extends Factory
{
    protected $model = Rating::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $booking = Booking::factory()->create();

        return [
            'booking_id' => $booking->id,
            'reviewer_user_id' => $booking->passenger_user_id,
            'reviewed_user_id' => User::factory(),
            'direction' => RatingDirection::PassengerToDriver,
            'stars' => 5,
            'comment' => 'رحلة ممتازة!',
        ];
    }

    public function visible(): static
    {
        return $this->state(fn (array $attributes) => ['visible_at' => now()]);
    }
}
