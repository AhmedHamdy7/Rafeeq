<?php

namespace Database\Factories;

use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Models\Booking;
use App\Domains\Payment\Enums\PaymentTransactionStatus;
use App\Domains\Payment\Enums\PaymentTransactionType;
use App\Domains\Payment\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $booking = Booking::factory()->create();

        return [
            'booking_id' => $booking->id,
            'user_id' => $booking->passenger_user_id,
            'payment_type' => PaymentType::Cash,
            'amount_piastres' => $booking->price_snapshot_piastres,
            'platform_fee_piastres' => $booking->platform_fee_snapshot_piastres,
            'driver_amount_piastres' => $booking->driver_amount_snapshot_piastres,
            'type' => PaymentTransactionType::Charge,
            'status' => PaymentTransactionStatus::SettledOffline,
            'idempotency_key' => 'booking_'.$booking->id.'_charge',
            'confirmed_by_driver_at' => now(),
        ];
    }

    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_type' => PaymentType::Online,
            'provider' => 'paymob',
            'provider_ref' => 'pmb_'.Str::random(10),
            'status' => PaymentTransactionStatus::Captured,
            'captured_at' => now(),
            'confirmed_by_driver_at' => null,
        ]);
    }
}
