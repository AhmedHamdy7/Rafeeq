<?php

namespace Database\Factories;

use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Reliability\Models\ReliabilityEventAppeal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReliabilityEventAppeal>
 */
class ReliabilityEventAppealFactory extends Factory
{
    protected $model = ReliabilityEventAppeal::class;

    public function definition(): array
    {
        return [
            'reliability_event_id' => ReliabilityEvent::factory(),
            'user_id' => fn (array $attributes) => ReliabilityEvent::query()->findOrFail($attributes['reliability_event_id'])->user_id,
            'round' => 1,
            'reason' => 'السواق ماجاش في الميعاد، مش أنا اللي اتأخرت.',
            'evidence_count' => 0,
        ];
    }
}
