<?php

namespace Database\Factories;

use App\Domains\Analytics\Models\RecommendationCache;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecommendationCache>
 */
class RecommendationCacheFactory extends Factory
{
    protected $model = RecommendationCache::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'commute_offer_id' => CommuteOffer::factory(),
            'score' => 88,
            'expires_at' => now()->addDay(),
        ];
    }
}
