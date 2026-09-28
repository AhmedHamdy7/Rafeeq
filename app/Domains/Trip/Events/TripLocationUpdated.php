<?php

namespace App\Domains\Trip\Events;

use App\Domains\Trip\ValueObjects\TripPosition;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * "The car has moved" — pushed to everybody on this run (decision D6: Reverb over
 * WebSockets).
 *
 * 🔒 A PRIVATE channel, authorised per run in `routes/channels.php`. A public channel would
 * be a live feed of where identifiable people are, readable by anybody who guessed a trip id
 * — which is the single worst thing this product could leak.
 *
 * 🔴 Not queued, deliberately. Every other broadcast in a Laravel app is better off queued,
 * and this one is the exception: a position is worth having for about five seconds, and a
 * queue worker that is a minute behind would deliver a car that has already arrived. If the
 * broadcast fails, the next ping is five seconds away — there is nothing worth retrying, and
 * `GET /v1/trips/{trip}/location` is the fallback for a client whose socket dropped.
 */
final class TripLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $tripSessionId,
        public TripPosition $position,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("trip.{$this->tripSessionId}");
    }

    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        /*
         * The position and nothing else. No passenger list, no driver name, no booking ids —
         * a client on this channel already knows who is on the run, and a channel payload is
         * the easiest thing in a system to end up logged by a proxy.
         */
        return $this->position->toArray();
    }
}
