<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\LiveShare;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LiveShare>
 */
class LiveShareFactory extends Factory
{
    protected $model = LiveShare::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_session_id' => TripSession::factory(),
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(32)),
            'expires_at' => now()->addHours(2),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => ['revoked_at' => now()]);
    }
}
