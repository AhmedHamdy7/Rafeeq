<?php

namespace Database\Factories;

use App\Domains\Geo\Models\Area;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Area>
 */
class AreaFactory extends Factory
{
    protected $model = Area::class;

    public function definition(): array
    {
        return [
            // Keyed by Google's place id, not a ULID.
            'google_place_id' => 'ChIJ'.fake()->unique()->bothify('????????????????????'),
            'name_en' => 'New Cairo',
            'name_ar' => 'القاهرة الجديدة',
            'city_en' => 'Cairo',
            'city_ar' => 'القاهرة',
            'lat' => 30.0300000,
            'lng' => 31.4700000,
        ];
    }
}
