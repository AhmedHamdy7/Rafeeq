<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The transactional outbox (Feature 03 v5: `outbox_events`).
 *
 * > *"Publish **idempotent** outbox events ... Websocket/push consumers must deduplicate event
 * > IDs."*
 *
 * 🔴 **Why a table rather than broadcasting straight from the Action.** Broadcasting inside a
 * transaction announces something that may still roll back; broadcasting after it can lose the
 * message if the process dies in between. Both failures are silent and both are the wrong way
 * round for a seat race: a client told "a seat opened" for a cancellation that rolled back will
 * try to take a seat that was never free, and a client never told will sit on a stale screen while
 * somebody else takes it. The row is written in the SAME transaction as the fact, and a separate
 * pass publishes it — so the event exists if and only if the thing happened.
 *
 * 🔴 **Idempotency has two halves and v5 asks for both.**
 *
 *   - **Producing** once: `dedupe_key` is unique. A cancellation processed twice — a retried job,
 *     an overlapping sweep — produces one event, not two. Without it a client would see the same
 *     seat open twice and show two chances at one seat.
 *   - **Consuming** once: `id` is the event id v5 tells consumers to deduplicate on. It is stable
 *     from the moment the row is written, so a message delivered twice by the broker is recognisably
 *     the same message.
 *
 * ⚠️ `payload` holds **ids and counts, never names**. It is the same rule the notification `data`
 * column follows, for the same reason: a broadcast payload is the easiest thing in a system to end
 * up in a proxy log, and this one goes out over a socket to a phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            /*
             * 🔴 The id v5 tells websocket and push consumers to deduplicate on. A ULID, so it is
             * also chronological — a client that reconnects can ask for everything after the last
             * id it saw without a separate sequence column.
             */
            $table->ulid('id')->primary();

            $table->string('type', 60);

            /*
             * Which private channel it belongs on, decided when the event is recorded rather than
             * when it is published: the Action knows who the change concerns, and the publisher
             * must not have to re-derive it from the payload.
             */
            $table->string('channel', 120);

            // Ids and counts only. See the class note.
            $table->json('payload');

            /*
             * What makes producing idempotent. Null for events that genuinely may repeat; set to
             * something stable — the state change's own identity — for everything else.
             */
            $table->string('dedupe_key', 160)->nullable()->unique();

            $table->dateTime('occurred_at');

            /*
             * When it reached the broker. Null means not yet, which is exactly what the publisher
             * looks for — so a crash mid-publish leaves the row to be picked up again rather than
             * losing it.
             */
            $table->dateTime('published_at')->nullable();

            /*
             * How many times publishing has been tried. Not a retry limit: it is there so a row
             * that cannot be delivered is findable instead of silently retried for ever.
             */
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();

            $table->timestamps();

            // The publisher's only query: the unpublished ones, oldest first.
            $table->index(['published_at', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
