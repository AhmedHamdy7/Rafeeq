<?php

namespace App\Console\Commands;

use App\Domains\Trip\Actions\SweepDelayedTripsAction;
use Illuminate\Console\Command;

/**
 * Walks the mornings that should have left and have not (Feature 03 v5 §240).
 *
 * Four rungs: a reminder to the driver, a warning to everybody holding a seat, a violation stamp,
 * and finally writing the day off. {@see SweepDelayedTripsAction} sets out why each one fires once
 * and why the expiry is the only rung that costs anybody anything.
 *
 * 🔴 If this does not run, nothing tells a woman standing on a kerb that her driver never set off,
 * and her seat stays `confirmed` on a morning that never happened — so her trip list claims a
 * journey she did not take, and the driver's record shows nothing at all.
 */
final class SweepDelayedTrips extends Command
{
    protected $signature = 'trips:sweep-delays';

    protected $description = 'Remind, warn and expire commutes that were never started';

    public function handle(SweepDelayedTripsAction $action): int
    {
        $counts = $action->sweep();

        $this->info(sprintf(
            'Reminded %d, warned %d, recorded %d violation(s), expired %d.',
            $counts['reminded'],
            $counts['warned'],
            $counts['violations'],
            $counts['expired'],
        ));

        return self::SUCCESS;
    }
}
