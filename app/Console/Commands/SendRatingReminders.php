<?php

namespace App\Console\Commands;

use App\Domains\Rating\Actions\SendRatingRemindersAction;
use Illuminate\Console\Command;

final class SendRatingReminders extends Command
{
    protected $signature = 'ratings:send-reminders';

    protected $description = 'Remind people who have not rated, in the last 24h of the rating window (Chapter 9).';

    public function handle(SendRatingRemindersAction $action): int
    {
        $sent = $action->execute();

        $this->info("Sent {$sent} rating reminders.");

        return self::SUCCESS;
    }
}
