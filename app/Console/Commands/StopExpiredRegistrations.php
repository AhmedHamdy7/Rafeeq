<?php

namespace App\Console\Commands;

use App\Domains\Driver\Actions\VehicleSuspensionAction;
use Illuminate\Console\Command;

final class StopExpiredRegistrations extends Command
{
    protected $signature = 'registrations:stop-expired';

    protected $description = 'Suspend cars whose registration has expired: pause their commutes and cancel upcoming seats';

    public function handle(VehicleSuspensionAction $action): int
    {
        $this->info(sprintf('Suspended %d car(s).', $action->sweepExpiredRegistrations()));

        return self::SUCCESS;
    }
}
