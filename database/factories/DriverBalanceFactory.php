<?php

namespace Database\Factories;

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Payment\Models\DriverBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverBalance>
 */
class DriverBalanceFactory extends Factory
{
    protected $model = DriverBalance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_profile_id' => DriverProfile::factory(),
            'outstanding_fee_piastres' => 0,
            'lifetime_earnings_piastres' => 0,
            'reconciled_at' => now(),
        ];
    }

    public function blocked(int $debtPiastres = 25000): static
    {
        return $this->state(fn (array $attributes) => [
            'outstanding_fee_piastres' => $debtPiastres,
            'is_blocked_from_publishing' => true,
            'block_reason' => 'الدين تعدّى الحد المسموح',
        ]);
    }
}
