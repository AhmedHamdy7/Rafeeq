<?php

namespace Database\Factories;

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Group\Enums\CommuteGroupStatus;
use App\Domains\Group\Models\CommuteGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommuteGroup>
 */
class CommuteGroupFactory extends Factory
{
    protected $model = CommuteGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commute_offer_id' => CommuteOffer::factory()->published(),
            'name' => 'الرحاب ← سمارت فيلدج · صباحًا',
            'status' => CommuteGroupStatus::Active,
            'min_commitment_days_per_week' => 3,
            'on_time_pct' => 98.0,
            'rides_together_count' => 0,
            'seats_open' => 3,
            'notice_period_days' => 7,
        ];
    }
}
