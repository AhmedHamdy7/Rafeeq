<?php

namespace App\Domains\Notification\Events;

use App\Domains\Outbox\Support\Outbox;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

final class ChatTyping implements ShouldBroadcastNow
{
    use Dispatchable;

    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $recipientId,
        public ?string $bookingId,
        public ?string $seatRequestId,
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
        return 'chat.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->eventId,
            'type' => $this->broadcastAs(),
            'occurredAt' => $this->occurredAt,
            'payload' => ['bookingId' => $this->bookingId, 'seatRequestId' => $this->seatRequestId],
        ];
    }
}
