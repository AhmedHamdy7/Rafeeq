<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtPaymentStatus;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebtPayment>
 */
class DebtPaymentFactory extends Factory
{
    protected $model = DebtPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'side' => DebtSide::Driver,
            'amount_piastres' => 18_000,
            'instapay_reference' => fake()->unique()->numerify('IP##########'),
            'status' => DebtPaymentStatus::PendingConfirmation,
        ];
    }
}
