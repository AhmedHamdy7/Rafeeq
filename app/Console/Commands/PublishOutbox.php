<?php

namespace App\Console\Commands;

use App\Domains\Outbox\Actions\PublishOutboxAction;
use Illuminate\Console\Command;

/**
 * Sends the outbox rows that have not gone out yet (Feature 03 v5).
 *
 * 🔴 Runs often, because what it carries is a seat race: a client told thirty seconds late that a
 * seat opened has lost it to whoever was told on time. Every minute is the schedule's floor; the
 * events are also published inline where the request can afford it, and this pass is what makes the
 * guarantee hold when it cannot.
 */
final class PublishOutbox extends Command
{
    protected $signature = 'outbox:publish {--limit=200}';

    protected $description = 'Broadcast the outbox events that have not been published yet';

    public function handle(PublishOutboxAction $action): int
    {
        $published = $action->execute((int) $this->option('limit'));

        $this->info("Published {$published} event(s).");

        return self::SUCCESS;
    }
}
