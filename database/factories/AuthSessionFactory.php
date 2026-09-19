<?php

namespace Database\Factories;

use App\Domains\Identity\Models\AuthSession;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuthSession>
 */
class AuthSessionFactory extends Factory
{
    protected $model = AuthSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_id' => Device::factory(),
            'refresh_token_hash' => hash('sha256', Str::random(40)),
            'token_family_id' => (string) Str::ulid(),
            'access_expires_at' => now()->addMinutes(15),
            'refresh_expires_at' => now()->addDays(60),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
            'revocation_reason' => 'user_logout',
        ]);
    }
}
