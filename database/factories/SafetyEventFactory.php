<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\SafetyEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SafetyEvent>
 */
class SafetyEventFactory extends Factory
{
    protected $model = SafetyEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => SafetyEventType::Sos,
            'user_id' => User::factory(),
            'severity' => SafetySeverity::Critical,
            'occurred_at' => now(),
        ];
    }
}
