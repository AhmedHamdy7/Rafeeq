<?php

namespace App\Console\Commands;

use App\Domains\Admin\Actions\AnnounceAction;
use Illuminate\Console\Command;

/**
 * Sends the announcements staff scheduled for later (Z01), once their time has come.
 */
final class SendDueAnnouncements extends Command
{
    protected $signature = 'announcements:send-due';

    protected $description = 'Queue the scheduled announcements whose time has come.';

    public function handle(AnnounceAction $announcements): int
    {
        $count = $announcements->sendDue();

        $this->info("Queued {$count} announcements.");

        return self::SUCCESS;
    }
}
