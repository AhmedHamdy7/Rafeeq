<?php

namespace App\Console\Commands;

use App\Domains\Payment\Actions\ReconcileDebtAccountsAction;
use Illuminate\Console\Command;

final class ReconcileDebtAccounts extends Command
{
    protected $signature = 'payments:reconcile-balances';

    protected $description = 'Compare every debt account (driver and passenger side) with its ledger and report drift (pitfall #39).';

    public function handle(ReconcileDebtAccountsAction $action): int
    {
        $drifted = $action->execute();

        if ($drifted > 0) {
            $this->error("{$drifted} debt accounts do not match their ledger. See the critical log.");

            return self::FAILURE;
        }

        $this->info('Every debt account matches its ledger.');

        return self::SUCCESS;
    }
}
