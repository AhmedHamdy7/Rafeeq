<?php

namespace Database\Factories;

use App\Domains\Geo\Models\PlacePinPick;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlacePinPick>
 */
class PlacePinPickFactory extends Factory
{
    protected $model = PlacePinPick::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Home',
            'lat' => 30.0400000,
            'lng' => 31.4800000,
        ];
    }
}
