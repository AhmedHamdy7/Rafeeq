<?php

namespace Database\Factories;

use App\Domains\Admin\Models\FeatureFlag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureFlag>
 */
class FeatureFlagFactory extends Factory
{
    protected $model = FeatureFlag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flag_key' => 'safety.night_escort_enabled',
            'enabled' => true,
            'rollout_percentage' => 100,
        ];
    }
}
