<?php

namespace Database\Factories;

use App\Domains\Outbox\Enums\OutboxEventType;
use App\Domains\Outbox\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OutboxEvent>
 */
class OutboxEventFactory extends Factory
{
    protected $model = OutboxEvent::class;

    /**
     * One unpublished seat-opened event.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tripId = (string) Str::ulid();

        return [
            'type' => OutboxEventType::SeatOpened,
            'channel' => "occurrence.{$tripId}",
            'payload' => ['occurrenceId' => $tripId, 'seatCount' => 1],
            'occurred_at' => now(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'published_at' => now(),
            'attempts' => 1,
        ]);
    }
}
