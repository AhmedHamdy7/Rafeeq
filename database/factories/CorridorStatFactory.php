<?php

namespace Database\Factories;

use App\Domains\Geo\Models\Corridor;
use App\Domains\Geo\Models\CorridorStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CorridorStat>
 */
class CorridorStatFactory extends Factory
{
    protected $model = CorridorStat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'corridor_id' => Corridor::factory(),
            'stat_date' => now()->toDateString(),
            'drivers' => fake()->numberBetween(5, 60),
            'seekers' => fake()->numberBetween(5, 150),
            'fill_pct' => fake()->randomFloat(2, 40, 100),
        ];
    }
}
