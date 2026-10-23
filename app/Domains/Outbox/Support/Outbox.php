<?php

namespace App\Domains\Outbox\Support;

use App\Domains\Notification\Support\Notifier;
use App\Domains\Outbox\Enums\OutboxEventType;
use App\Domains\Outbox\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Recording something that happened, so a watching client can be told exactly once
 * (Feature 03 v5).
 *
 * 🔴 **Called from inside the transaction that makes the change.** That is the whole of the
 * transactional outbox pattern and the reason the table exists rather than a `broadcast()` call:
 * broadcasting inside a transaction announces something that may still roll back, and broadcasting
 * after it loses the message if the process dies in between. Both failures are silent, and for a
 * seat race both are the wrong way round — a client told a seat opened for a rolled-back
 * cancellation will reach for a seat that was never free.
 *
 * 🔴 **`dedupeKey` is what makes producing idempotent**, which v5 asks for in as many words. The
 * cancelling and fan-out paths are retried — a replayed job, an overlapping sweep — and without a
 * stable key the same seat would open twice on somebody's screen, offering two chances at one
 * chair. Pass the identity of the STATE CHANGE, never a timestamp or a random value.
 *
 * ⚠️ **What this does not publish yet.** v5's list is longer than
 * {@see OutboxEventType}: a seat request raised, a counteroffer, an approval, a waiting-list
 * approval, a booking switched, a pickup arrival, a late warning after the trip, a driver delay or
 * expiry, a proposal revision, an SOS or a report. Those still reach people through
 * {@see Notifier}, which writes the inbox row in the caller's
 * transaction and pushes after commit — so nobody misses them; they are simply not on the socket
 * stream yet, and a client cannot watch for them live. They are named here rather than declared as
 * silent enum cases, because an event type nothing emits tells a client to wait for something that
 * never comes.
 */
final class Outbox
{
    /**
     * @param  array<string, mixed>  $payload  ids and counts only — never names. A broadcast
     *                                         payload is the easiest thing in a system to end up in
     *                                         a proxy log, and this one goes to a phone.
     * @param  string|null  $dedupeKey  the identity of the state change; null only where the event
     *                                  genuinely may repeat
     */
    public static function record(
        OutboxEventType $type,
        string $channel,
        array $payload,
        ?string $dedupeKey = null,
        ?CarbonImmutable $occurredAt = null,
    ): ?OutboxEvent {
        /*
         * Checked before inserting as well as being enforced by the unique index. The index is
         * what makes it true under a race; this is what keeps an ordinary retry from raising an
         * exception the caller would have to catch inside its own transaction.
         */
        if ($dedupeKey !== null && OutboxEvent::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $event = new OutboxEvent;

        $event->forceFill([
            // The id v5 tells consumers to deduplicate on, stable from this moment.
            'id' => (string) Str::ulid(),
            'type' => $type->value,
            'channel' => $channel,
            'payload' => $payload,
            'dedupe_key' => $dedupeKey,
            'occurred_at' => $occurredAt ?? CarbonImmutable::now(),
        ])->save();

        return $event;
    }

    /**
     * The private channel for everybody involved in one day of one commute.
     *
     * 🔒 Per occurrence, not per commute. A commute's channel would carry every day's seat races to
     * everybody who has ever ridden it, including people with no booking on the day in question —
     * and `routes/channels.php` authorises on a live booking for THAT day.
     */
    public static function occurrenceChannel(string $scheduledTripId): string
    {
        return "occurrence.{$scheduledTripId}";
    }

    /**
     * One person's own channel, for news that is hers alone.
     */
    public static function userChannel(string $userId): string
    {
        return "user.{$userId}";
    }
}
