<?php

namespace Database\Factories;

use App\Domains\Payment\Models\PaymentWebhook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentWebhook>
 */
class PaymentWebhookFactory extends Factory
{
    protected $model = PaymentWebhook::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'paymob',
            'event_id' => (string) Str::ulid(),
            'event_type' => 'transaction.processed',
            'payload' => ['obj' => ['success' => true]],
            'signature_valid' => true,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => ['processed_at' => now()]);
    }
}
