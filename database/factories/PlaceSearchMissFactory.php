<?php

namespace Database\Factories;

use App\Domains\Geo\Models\PlaceSearchMiss;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaceSearchMiss>
 */
class PlaceSearchMissFactory extends Factory
{
    protected $model = PlaceSearchMiss::class;

    public function definition(): array
    {
        return [
            'query' => 'كمبوند الندى',
            'normalised_query' => 'كمبوند الندي',
            'lat' => 30.06,
            'lng' => 31.49,
        ];
    }
}
