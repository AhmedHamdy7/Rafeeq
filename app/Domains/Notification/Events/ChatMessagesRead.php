<?php

namespace App\Domains\Notification\Events;

use App\Domains\Notification\Models\Conversation;
use App\Domains\Outbox\Support\Outbox;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * "They have read your messages", pushed to the person who wrote them, so the ticks change without
 * a refetch. Everything in the thread up to `readAt` is read: opening a thread reads all of it.
 *
 * Tells the writer nothing that `readAt` on `GET .../messages` doesn't already tell them.
 */
final class ChatMessagesRead implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public string $eventId;

    public function __construct(
        public string $writerId,
        public Conversation $conversation,
        public string $readAt,
    ) {
        $this->eventId = (string) Str::ulid();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(Outbox::userChannel($this->writerId));
    }

    public function broadcastAs(): string
    {
        return 'chat.read';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->eventId,
            'type' => $this->broadcastAs(),
            'occurredAt' => $this->readAt,
            'payload' => $this->conversation->threadIds() + ['readAt' => $this->readAt],
        ];
    }
}
