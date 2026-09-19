<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\UserPlaceLabel;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPlace;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPlace>
 */
class UserPlaceFactory extends Factory
{
    protected $model = UserPlace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lat = fake()->latitude(29.8, 30.2);
        $lng = fake()->longitude(31.0, 31.6);

        return [
            'user_id' => User::factory(),
            'label' => UserPlaceLabel::Home,
            'display_name' => 'المنزل',
            'point' => new Coordinate(lat: $lat, lng: $lng),
            'lat' => $lat,
            'lng' => $lng,
            'address' => fake()->streetAddress(),
            'is_default_origin' => true,
            'blur_radius_meters' => 200,
        ];
    }

    public function work(): static
    {
        return $this->state(fn (array $attributes) => [
            'label' => UserPlaceLabel::Work,
            'display_name' => 'العمل',
            'is_default_origin' => false,
        ]);
    }
}
