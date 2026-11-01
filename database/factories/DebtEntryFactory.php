<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Support\SettlementWeek;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A raw ledger line, for model-level tests only. Real lines come from `DebtLedger`, which also
 * keeps the projection in step — a line made here leaves `debt_accounts` untouched.
 *
 * @extends Factory<DebtEntry>
 */
class DebtEntryFactory extends Factory
{
    protected $model = DebtEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'side' => DebtSide::Driver,
            'type' => DebtEntryType::Commission,
            'amount_piastres' => 1_000,
            'balance_after_piastres' => 1_000,
            'reason_code' => 'seat_commission',
            'week_of' => SettlementWeek::weekOf(now()),
        ];
    }
}
