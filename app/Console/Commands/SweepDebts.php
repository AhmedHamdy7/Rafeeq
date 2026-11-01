<?php

namespace App\Console\Commands;

use App\Domains\Payment\Actions\SweepDebtsAction;
use Illuminate\Console\Command;

final class SweepDebts extends Command
{
    protected $signature = 'payments:sweep-debts';

    protected $description = 'The weekly debt cycle: late fees, reminders and suspensions for debt (PAY-11 → PAY-17, PAY-45).';

    public function handle(SweepDebtsAction $action): int
    {
        $done = $action->execute();

        $this->info("Late fees: {$done['lateFees']}, suspended: {$done['suspended']}, reminded: {$done['reminded']}.");

        return self::SUCCESS;
    }
}
