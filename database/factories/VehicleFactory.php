<?php

namespace Database\Factories;

use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plate = fake()->unique()->numerify('### ###');

        return [
            'driver_profile_id' => DriverProfile::factory(),
            'make' => fake()->randomElement(['Kia', 'Hyundai', 'Chevrolet', 'Nissan']),
            'model' => fake()->randomElement(['Sportage', 'Elantra', 'Optra', 'Sunny']),
            'year' => fake()->numberBetween(2015, 2026),
            'colour' => fake()->randomElement(['أبيض', 'فضي', 'أسود', 'أزرق']),
            'plate_number' => $plate,
            'plate_normalized' => Vehicle::normalizePlate($plate),
            'seats' => fake()->numberBetween(4, 6),
            'transmission' => fake()->randomElement(['automatic', 'manual']),
            'fuel_type' => 'petrol',
            'is_active' => true,
            'verification_status' => VehicleVerificationStatus::Approved,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
