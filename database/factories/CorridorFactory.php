<?php

namespace Database\Factories;

use App\Domains\Geo\Enums\CorridorStatus;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Geo\Models\Place;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Corridor>
 */
class CorridorFactory extends Factory
{
    protected $model = Corridor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Rehab → Smart Village',
            'name_ar' => 'الرحاب ← القرية الذكية',
            'origin_place_id' => Place::factory(),
            'destination_place_id' => Place::factory(),
            'window_start' => '06:45:00',
            'window_end' => '08:00:00',
            'days_mask' => DaysMask::weekdaysSunToThu()->value,
            'status' => CorridorStatus::Healthy,
        ];
    }
}
