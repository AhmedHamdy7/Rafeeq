<?php

namespace App\Domains\Outbox\Actions;

use App\Domains\Outbox\Events\OutboxEventPublished;
use App\Domains\Outbox\Models\OutboxEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the outbox rows that have not gone out yet (Feature 03 v5).
 *
 * 🔴 **A row stays pending until it is actually delivered.** `published_at` is set after the
 * broadcast returns, never before — so a process that dies mid-publish leaves the row to be picked
 * up again. The cost of that choice is a possible duplicate delivery, which is exactly what v5
 * tells consumers to handle: *"Websocket/push consumers must deduplicate event IDs."* The
 * alternative — marking it sent first — trades a duplicate for a silent loss, and for a seat race a
 * lost message leaves somebody on a stale screen while her seat goes.
 *
 * 🔴 **One failure does not stop the pass.** A broker rejecting one message must not hold up every
 * other person's seat news behind it. The failure is counted and recorded on the row, which is what
 * makes an undeliverable event findable rather than retried for ever in silence.
 */
final readonly class PublishOutboxAction
{
    /**
     * @return int how many were published
     */
    public function execute(int $limit = 200): int
    {
        $pending = OutboxEvent::query()->pending()->limit($limit)->get();

        $published = 0;

        foreach ($pending as $event) {
            try {
                OutboxEventPublished::dispatch($event);

                // After the broadcast, deliberately. See the class note.
                $event->forceFill([
                    'published_at' => now(),
                    'attempts' => $event->attempts + 1,
                ])->save();

                $published++;
            } catch (Throwable $e) {
                /*
                 * Counted and recorded, and the pass continues. `published_at` stays null so the
                 * next pass tries again — and `attempts` climbing with no `published_at` is the
                 * signal that something is wrong with this particular message rather than with the
                 * broker.
                 */
                $event->forceFill([
                    'attempts' => $event->attempts + 1,
                    'last_error' => mb_substr($e->getMessage(), 0, 255),
                ])->save();

                Log::warning('Outbox event could not be published.', [
                    'outbox_event_id' => $event->getKey(),
                    'type' => $event->type->value,
                    'attempts' => $event->attempts,
                ]);
            }
        }

        return $published;
    }
}
