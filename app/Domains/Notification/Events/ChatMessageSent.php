<?php

namespace App\Domains\Notification\Events;

use App\Domains\Notification\Models\Conversation;
use App\Domains\Notification\Models\Message;
use App\Domains\Outbox\Support\Outbox;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * "You have a new message", pushed to the person it was written to.
 *
 * 🔒 On the receiver's own channel, not a channel per thread. A thread's channel would need a second
 * set of rules in `routes/channels.php` (window, block, participants), and a subscription outlives
 * the check that admitted it. `SendChatMessageAction` has already refused a closed thread and a
 * blocked pair by the time this exists, so an event can only ever reach somebody allowed to get it.
 * It also keeps sender ids out of the payload: only the receiver gets this, so `mine` is always false.
 *
 * 🔴 Queued and after commit, unlike the position broadcast. A message has already been saved when
 * this runs, so a Reverb outage must not turn the sender's `201` into a `500`, and a rolled-back
 * message must never reach anybody. `GET .../messages` is the fallback for a dropped socket.
 *
 * ⚠️ `SerializesModels` keeps the body out of `jobs` and `failed_jobs`: only the ids are stored, so
 * the text doesn't outlive the message's retention in a table nobody prunes for it.
 */
final class ChatMessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $recipientId,
        public Message $message,
        public Conversation $conversation,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(Outbox::userChannel($this->recipientId));
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            // The message's own id: a client that also refetched the thread can drop the repeat.
            'id' => $this->message->id,
            'type' => $this->broadcastAs(),
            'occurredAt' => $this->message->created_at->toIso8601String(),
            'payload' => $this->conversation->threadIds() + [
                // The same shape as `ChatMessageResource`, from the receiver's side.
                'message' => [
                    'id' => $this->message->id,
                    'mine' => false,
                    'body' => $this->message->body,
                    'containsContactInfo' => $this->message->contains_contact_info,
                    'readAt' => $this->message->read_at?->toIso8601String(),
                    'sentAt' => $this->message->created_at->toIso8601String(),
                ],
            ],
        ];
    }
}
