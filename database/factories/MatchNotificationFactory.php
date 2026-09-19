<?php

namespace Database\Factories;

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchNotification>
 */
class MatchNotificationFactory extends Factory
{
    protected $model = MatchNotification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commute_demand_id' => CommuteDemand::factory(),
            'commute_offer_id' => CommuteOffer::factory()->published(),
            'score' => 92,
            'delivered_at' => now(),
        ];
    }
}
