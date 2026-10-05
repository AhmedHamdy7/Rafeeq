<?php

namespace App\Console\Commands;

use App\Domains\Matching\Actions\SaveCommuteDemandAction;
use Illuminate\Console\Command;

final class ExpireCommuteDemands extends Command
{
    protected $signature = 'demands:expire';

    protected $description = 'Mark saved ride requests past their expiry as expired (Chapter 6).';

    public function handle(): int
    {
        $expired = SaveCommuteDemandAction::expireOverdue();

        $this->info("Expired {$expired} saved ride requests.");

        return self::SUCCESS;
    }
}
