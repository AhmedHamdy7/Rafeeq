<?php

namespace Database\Factories;

use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\IncidentAppeal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentAppeal>
 */
class IncidentAppealFactory extends Factory
{
    protected $model = IncidentAppeal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'user_id' => fn (array $attributes) => Incident::query()->findOrFail($attributes['incident_id'])->reporter_user_id,
            'round' => 1,
            'reason' => 'القرار ماخدش في اعتباره إن السواق نزّلني في مكان مقطوع.',
            'evidence_count' => 0,
        ];
    }
}
