<?php

namespace Database\Factories;

use App\Domains\Admin\Enums\AdminStatus;
use App\Domains\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<AdminUser>
 */
class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('password'),
            /*
             * A REAL base32 TOTP secret, not a placeholder.
             *
             * This was `bin2hex(random_bytes(16))`, which is hex — Google2FA reads
             * secrets as base32, so no code generated from it could ever verify. Any
             * test of the MFA step would have been testing that a correct code is
             * rejected, and the first real admin created from this factory could not
             * have signed in.
             */
            'mfa_secret' => app(Google2FA::class)->generateSecretKey(),
            'mfa_confirmed_at' => now(),
            'status' => AdminStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => AdminStatus::Suspended]);
    }
}
