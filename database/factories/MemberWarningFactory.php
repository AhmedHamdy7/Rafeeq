<?php

namespace Database\Factories;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberWarning>
 */
class MemberWarningFactory extends Factory
{
    protected $model = MemberWarning::class;

    /**
     * A first lateness warning, already delivered.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => WarningKind::LateBoarding,
            'booking_id' => Booking::factory(),
            'ordinal' => 1,
            'fee_eligible' => false,
            'occurred_on' => now()->subDay()->toDateString(),
            'delivered_at' => now()->subDay(),
        ];
    }

    /**
     * Recorded and not yet told — a lateness waiting for her drop-off.
     */
    public function undelivered(): static
    {
        return $this->state(fn (array $attributes) => ['delivered_at' => null]);
    }

    /**
     * The one where a fee becomes possible.
     */
    public function feeEligible(): static
    {
        return $this->state(fn (array $attributes) => [
            'ordinal' => 3,
            'fee_eligible' => true,
        ]);
    }
}
