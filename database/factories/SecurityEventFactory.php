<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\SecurityRiskLevel;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityEvent>
 */
class SecurityEventFactory extends Factory
{
    protected $model = SecurityEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_type' => fake()->randomElement(['login_success', 'otp_requested', 'device_revoked']),
            'risk_level' => SecurityRiskLevel::Low,
            'metadata' => [],
        ];
    }
}
