<?php

namespace Database\Factories;

use App\Domains\Commute\Enums\CommuteRuleKey;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommuteRule>
 */
class CommuteRuleFactory extends Factory
{
    protected $model = CommuteRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commute_offer_id' => CommuteOffer::factory(),
            'rule_key' => CommuteRuleKey::NonSmoking,
            'rule_value' => true,
        ];
    }
}
