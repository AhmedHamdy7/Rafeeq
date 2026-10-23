<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Models\ReliabilityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReliabilityEvent>
 */
class ReliabilityEventFactory extends Factory
{
    protected $model = ReliabilityEvent::class;

    /**
     * One driver cancellation on somebody's record.
     *
     * The weight is taken from the kind, as the ledger does, so a factory row and a real one agree
     * about what the same event costs.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kind = ReliabilityEventKind::DriverCancelledBooking;

        return [
            'user_id' => User::factory(),
            'role' => $kind->role(),
            'kind' => $kind,
            'weight' => $kind->weight(),
            'occurred_at' => now(),
        ];
    }

    public function of(ReliabilityEventKind $kind): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $kind->role(),
            'kind' => $kind,
            'weight' => $kind->weight(),
        ]);
    }

    /**
     * An appeal was approved, so it stops counting and stays readable (Feature 03 v5).
     */
    public function reversed(): static
    {
        return $this->state(fn (array $attributes) => [
            'reversed_at' => now(),
            'reversed_reason' => 'Appeal approved with evidence.',
        ]);
    }

    /**
     * Old enough that the score's window has moved past it, while the row itself remains —
     * v5: "Score/history persist after reinstatement."
     */
    public function outsideTheWindow(): static
    {
        return $this->state(fn (array $attributes) => [
            'occurred_at' => now()->subDays((int) config('rafeeq.reliability.window_days') + 30),
        ]);
    }
}
