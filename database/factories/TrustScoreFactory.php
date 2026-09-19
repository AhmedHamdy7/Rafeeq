<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\PublicTrustTier;
use App\Domains\Verification\Models\TrustScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrustScore>
 */
class TrustScoreFactory extends Factory
{
    protected $model = TrustScore::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'score' => fake()->numberBetween(60, 100),
            'public_tier' => PublicTrustTier::Trusted,
            'computed_at' => now(),
        ];
    }
}
