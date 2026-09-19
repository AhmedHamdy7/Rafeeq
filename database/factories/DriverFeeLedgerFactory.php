<?php

namespace Database\Factories;

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Payment\Enums\DriverFeeLedgerType;
use App\Domains\Payment\Models\DriverFeeLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverFeeLedger>
 */
class DriverFeeLedgerFactory extends Factory
{
    protected $model = DriverFeeLedger::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_profile_id' => DriverProfile::factory(),
            'type' => DriverFeeLedgerType::FeeDue,
            'amount_piastres' => 240,
            'balance_after_piastres' => 240,
        ];
    }

    public function settled(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DriverFeeLedgerType::FeeSettled,
            'amount_piastres' => -240,
            'balance_after_piastres' => 0,
        ]);
    }
}
