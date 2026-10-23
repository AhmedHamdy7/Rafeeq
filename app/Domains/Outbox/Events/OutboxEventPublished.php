<?php

namespace App\Domains\Outbox\Events;

use App\Domains\Outbox\Models\OutboxEvent;
use App\Domains\Trip\Events\TripLocationUpdated;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One outbox row going out over the socket (Feature 03 v5).
 *
 * 🔒 A PRIVATE channel, authorised per occurrence or per person in `routes/channels.php`. A public
 * one would be a live feed of who is taking which seat on which morning, readable by anybody who
 * guessed an id — the same reasoning that keeps {@see TripLocationUpdated}
 * private, and the stakes are similar.
 *
 * 🔴 `ShouldBroadcastNow`, not `ShouldBroadcast`, and that is not an optimisation. The durability
 * this needs is already provided by the outbox row: if the broadcast fails, `published_at` stays
 * null and the next pass tries again. Putting it on the queue as well would mean two mechanisms
 * both responsible for not losing it, and the queue's copy would be the one nobody could see the
 * state of.
 *
 * ⚠️ The payload is the row's own `id`, `type` and `payload` — ids and counts, never names. v5 tells
 * consumers to deduplicate on that id, which is why it travels in the message rather than only in
 * the table.
 */
final class OutboxEventPublished implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(private readonly OutboxEvent $event) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->event->channel);
    }

    public function broadcastAs(): string
    {
        return $this->event->type->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            /*
             * 🔴 The event id, in the message. v5: "Websocket/push consumers must deduplicate event
             * IDs" — which a consumer can only do if the id arrives with the event rather than
             * being something it has to look up.
             */
            'id' => (string) $this->event->getKey(),
            'type' => $this->event->type->value,
            'occurredAt' => $this->event->occurred_at->toIso8601String(),
            'payload' => $this->event->payload,
        ];
    }
}
