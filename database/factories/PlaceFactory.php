<?php

namespace Database\Factories;

use App\Domains\Geo\Enums\PlaceType;
use App\Domains\Geo\Models\Place;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Place>
 */
class PlaceFactory extends Factory
{
    protected $model = Place::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lat = fake()->latitude(29.8, 30.2);
        $lng = fake()->longitude(31.0, 31.6);

        return [
            'name' => fake()->streetName().' Gate',
            'name_ar' => 'بوابة '.fake()->streetName(),
            'type' => fake()->randomElement(PlaceType::cases()),
            'point' => new Coordinate(lat: $lat, lng: $lng),
            'lat' => $lat,
            'lng' => $lng,
            'city' => 'القاهرة',
            'district' => fake()->randomElement(['القاهرة الجديدة', 'الشيخ زايد', '6 أكتوبر']),
            'is_public' => true,
            'usage_count' => fake()->numberBetween(0, 5000),
        ];
    }
}
