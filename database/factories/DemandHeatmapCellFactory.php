<?php

namespace Database\Factories;

use App\Domains\Analytics\Models\DemandHeatmapCell;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandHeatmapCell>
 */
class DemandHeatmapCellFactory extends Factory
{
    protected $model = DemandHeatmapCell::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cell_geohash' => 'stq5c8',
            'cell_date' => now()->toDateString(),
            'hour_bucket' => 7,
            'demand_count' => 12,
            'supply_count' => 8,
        ];
    }
}
