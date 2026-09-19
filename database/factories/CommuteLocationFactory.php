<?php

namespace Database\Factories;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommuteLocation>
 */
class CommuteLocationFactory extends Factory
{
    protected $model = CommuteLocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lat = fake()->latitude(29.8, 30.2);
        $lng = fake()->longitude(31.0, 31.6);

        return [
            'commute_offer_id' => CommuteOffer::factory(),
            'type' => CommuteLocationType::Origin,
            'point' => new Coordinate(lat: $lat, lng: $lng),
            'lat' => $lat,
            'lng' => $lng,
            'address' => fake()->streetAddress(),
            'sequence' => 0,
            'is_exact' => true,
        ];
    }

    public function destination(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CommuteLocationType::Destination,
            'sequence' => 999,
        ]);
    }
}
