<?php

namespace Database\Factories;

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommuteWeekdaySchedule>
 */
class CommuteWeekdayScheduleFactory extends Factory
{
    protected $model = CommuteWeekdaySchedule::class;

    /**
     * Capacity defaults to null — "follow the commute" — because that is what a real row holds for
     * the ordinary driver who offers the same every day. A factory that filled it in would make
     * every inheritance test pass for the wrong reason. (There is no per-day price: PAY-42.)
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commute_offer_id' => CommuteOffer::factory(),
            'weekday_bit' => DaysMask::SUNDAY,
            'departure_time' => '07:05:00',
            'promised_arrival_time' => null,
            'seats_total' => null,
        ];
    }

    public function on(int $weekdayBit): static
    {
        return $this->state(fn (): array => ['weekday_bit' => $weekdayBit]);
    }
}
