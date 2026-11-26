<?php

namespace App\Domains\Trip\Events;

use App\Domains\Outbox\Support\Outbox;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

final class PickupEtaUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public string $eventId;

    public string $occurredAt;

    /**
     * @param  array{pickupEtaAt: string|null, carToPickupPolyline: string|null, stopsBeforeYou: int|null}  $eta
     */
    public function __construct(
        public string $recipientId,
        public string $tripId,
        public string $tripSessionId,
        public string $bookingId,
        public array $eta,
    ) {
        $this->eventId = (string) Str::ulid();
        $this->occurredAt = now()->toIso8601String();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(Outbox::userChannel($this->recipientId));
    }

    public function broadcastAs(): string
    {
        return 'trip.pickup_eta';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->eventId,
            'type' => $this->broadcastAs(),
            'occurredAt' => $this->occurredAt,
            'payload' => [
                'tripId' => $this->tripId,
                'tripSessionId' => $this->tripSessionId,
                'bookingId' => $this->bookingId,
                'pickupEtaAt' => $this->eta['pickupEtaAt'],
                'stopsBeforeYou' => $this->eta['stopsBeforeYou'],
            ],
        ];
    }
}
