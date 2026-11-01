<?php

namespace Database\Factories;

use App\Domains\Driver\Enums\VehicleTier;
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
            // Reviewed: a car the factory hands out is ready to carry a published commute. Three
            // approved seats in a Standard car is the reference car of PAY-42's examples.
            'approved_seats' => 3,
            'tier' => VehicleTier::Standard,
            'has_ac' => true,
            'transmission' => fake()->randomElement(['automatic', 'manual']),
            'fuel_type' => 'petrol',
            'is_active' => true,
            'verification_status' => VehicleVerificationStatus::Approved,
        ];
    }

    /** A car nobody has reviewed yet: no approved seats, so it cannot publish (F2-6). */
    public function unreviewed(): static
    {
        return $this->state(fn (array $attributes) => ['approved_seats' => null]);
    }

    public function comfort(): static
    {
        return $this->state(fn (array $attributes) => ['tier' => VehicleTier::Comfort]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
