<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\IncidentCategory;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reporter_user_id' => User::factory(),
            'category' => IncidentCategory::UnsafeDriving,
            'severity' => SafetySeverity::High,
            'description' => 'السائقة كانت بتسرع جدًا وبتتخطى إشارات.',
        ];
    }
}
