<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\PaymentMethodType;
use App\Domains\Payment\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'paymob',
            'provider_token' => 'tok_'.fake()->uuid(),
            'type' => PaymentMethodType::Card,
            'last4' => (string) fake()->numberBetween(1000, 9999),
            'brand' => 'visa',
            'is_default' => true,
            'expires_at' => now()->addYears(3)->toDateString(),
        ];
    }
}
