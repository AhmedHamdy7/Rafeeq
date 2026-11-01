<?php

namespace App\Console\Commands;

use App\Domains\Trip\Actions\SendTripRemindersAction;
use Illuminate\Console\Command;

/**
 * "Your commute leaves in an hour" / "…in 10 minutes", to both sides (Feature 03 files §8).
 *
 * Each fires once per day; see {@see SendTripRemindersAction}. If this does not run, nobody is
 * reminded — the commute still happens, but the reminder the partners asked for never arrives.
 */
final class SendTripReminders extends Command
{
    protected $signature = 'trips:send-reminders';

    protected $description = 'Remind drivers and passengers an hour and ten minutes before a commute';

    public function handle(SendTripRemindersAction $action): int
    {
        $counts = $action->sweep();

        $this->info(sprintf('Sent %d hour reminder(s) and %d final reminder(s).', $counts['early'], $counts['final']));

        return self::SUCCESS;
    }
}
