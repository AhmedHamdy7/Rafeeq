<?php

namespace App\Console\Commands;

use App\Domains\Trip\Actions\AutoFinishTripsAction;
use Illuminate\Console\Command;

/**
 * Closes runs that are plainly over and that the driver never closed (Feature 03 v5).
 *
 * 🔴 Only when nobody is in the car and nobody is still awaiting a decision. The condition is the
 * whole safety of this, and {@see AutoFinishTripsAction} sets out why: closing a run with somebody
 * aboard would cut her live location while she is moving, and closing one with a `pending`
 * attendance row would freeze a decision nobody made.
 *
 * So what this tidies is forgetfulness, and nothing else. A driver stuck in traffic taps "still
 * driving" and buys herself another fifteen minutes, as often as she needs to.
 */
final class AutoFinishTrips extends Command
{
    protected $signature = 'trips:auto-finish';

    protected $description = 'Close runs long past their promised arrival with nobody aboard or awaiting a decision';

    public function handle(AutoFinishTripsAction $action): int
    {
        $finished = $action->sweep();

        $this->info("Finished {$finished} run(s).");

        return self::SUCCESS;
    }
}
