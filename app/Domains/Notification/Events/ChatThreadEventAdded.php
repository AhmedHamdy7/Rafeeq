<?php

namespace App\Domains\Notification\Events;

use App\Domains\Outbox\Support\Outbox;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

final class ChatThreadEventAdded implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $eventId;

    public function __construct(
        public string $recipientId,
        public string $bookingId,
        public array $item,
    ) {
        $this->eventId = (string) Str::ulid();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(Outbox::userChannel($this->recipientId));
    }

    public function broadcastAs(): string
    {
        return 'chat.event';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->eventId,
            'type' => $this->broadcastAs(),
            'occurredAt' => $this->item['at'],
            'payload' => ['bookingId' => $this->bookingId, 'seatRequestId' => null, 'event' => $this->item],
        ];
    }
}
