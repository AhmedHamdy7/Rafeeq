<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebtAccount>
 */
class DebtAccountFactory extends Factory
{
    protected $model = DebtAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'side' => DebtSide::Driver,
            'balance_piastres' => 0,
        ];
    }
}
