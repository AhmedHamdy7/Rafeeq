<?php

namespace App\Console\Commands;

use App\Domains\Payment\Actions\ReconcileDriverBalancesAction;
use Illuminate\Console\Command;

final class ReconcileDriverBalances extends Command
{
    protected $signature = 'payments:reconcile-balances';

    protected $description = 'Compare every driver balance with its fee ledger and report drift (pitfall #39).';

    public function handle(ReconcileDriverBalancesAction $action): int
    {
        $drifted = $action->execute();

        if ($drifted > 0) {
            $this->error("{$drifted} driver balances do not match their ledger. See the critical log.");

            return self::FAILURE;
        }

        $this->info('Every driver balance matches its ledger.');

        return self::SUCCESS;
    }
}
