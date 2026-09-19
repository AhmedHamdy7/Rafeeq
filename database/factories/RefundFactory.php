<?php

namespace Database\Factories;

use App\Domains\Payment\Enums\RefundStatus;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'amount_piastres' => 8800,
            'reason' => 'السائقة ألغت الرحلة',
            'status' => RefundStatus::Pending,
        ];
    }
}
