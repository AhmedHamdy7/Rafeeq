<?php

namespace Database\Factories;

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteSchedule;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommuteSchedule>
 */
class CommuteScheduleFactory extends Factory
{
    protected $model = CommuteSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commute_offer_id' => CommuteOffer::factory(),
            'days_mask' => DaysMask::weekdaysSunToThu()->value,
            'departure_time' => '07:05:00',
            'timezone' => 'Africa/Cairo',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(4)->toDateString(),
        ];
    }
}
