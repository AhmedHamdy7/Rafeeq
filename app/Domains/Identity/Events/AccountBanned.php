<?php

namespace App\Domains\Identity\Events;

use App\Domains\Outbox\Support\Outbox;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

final class AccountBanned implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $userId,
        public ?string $caseNumber,
        public ?string $activeTripId,
    ) {
        $this->eventId = (string) Str::ulid();
        $this->occurredAt = now()->toIso8601String();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(Outbox::userChannel($this->userId));
    }

    public function broadcastAs(): string
    {
        return 'account.banned';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->eventId,
            'type' => $this->broadcastAs(),
            'occurredAt' => $this->occurredAt,
            'payload' => [
                'caseNumber' => $this->caseNumber,
                'permanent' => true,
                'activeTripId' => $this->activeTripId,
            ],
        ];
    }
}
