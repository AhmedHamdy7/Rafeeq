<?php

namespace Database\Factories;

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Payment\Enums\PayoutMethod;
use App\Domains\Payment\Enums\PayoutStatus;
use App\Domains\Payment\Models\Payout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_profile_id' => DriverProfile::factory(),
            'period_start' => now()->startOfWeek()->toDateString(),
            'period_end' => now()->endOfWeek()->toDateString(),
            'amount_piastres' => 34200,
            'method' => PayoutMethod::Instapay,
            'status' => PayoutStatus::Cleared,
        ];
    }
}
