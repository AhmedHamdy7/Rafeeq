<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\OrganizationType;
use App\Domains\Identity\Models\Organization;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'name_ar' => fake()->company(),
            'type' => fake()->randomElement(OrganizationType::cases()),
            'email_domain' => fake()->domainName(),
            'city' => 'القاهرة',
            'district' => fake()->randomElement(['القاهرة الجديدة', 'الشيخ زايد', '6 أكتوبر', 'المعادي']),
            'location_point' => new Coordinate(lat: fake()->latitude(29.8, 30.2), lng: fake()->longitude(31.0, 31.6)),
            'is_verified' => true,
        ];
    }
}
