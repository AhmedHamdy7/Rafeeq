<?php

namespace Database\Factories;

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Matching\Models\MatchScore;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MatchScore>
 */
class MatchScoreFactory extends Factory
{
    protected $model = MatchScore::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'demand_signature' => hash('sha256', Str::random(20)),
            'scheduled_trip_id' => ScheduledTrip::factory(),
            'total' => 96,
            'overlap_score' => 28,
            'schedule_score' => 24,
            'detour_score' => 15,
            'audience_score' => 10,
            'comfort_score' => 9,
            'price_score' => 5,
            'reliability_score' => 5,
            'walk_minutes' => 4.0,
            'detour_minutes' => 6.0,
            'expires_at' => now()->addHour(),
        ];
    }
}
