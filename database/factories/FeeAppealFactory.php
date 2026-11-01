<?php

namespace Database\Factories;

use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\FeeAppeal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An undecided appeal against a driver fee.
 *
 * @extends Factory<FeeAppeal>
 */
class FeeAppealFactory extends Factory
{
    protected $model = FeeAppeal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $entry = DebtEntry::factory()->create(['type' => DebtEntryType::Fee, 'reason_code' => 'driver_cancel']);

        return [
            'debt_entry_id' => $entry->id,
            'user_id' => $entry->user_id,
            'reason' => 'The car broke down; the garage receipt is attached to my report.',
        ];
    }
}
