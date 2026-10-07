<?php

namespace App\Console\Commands;

use App\Domains\Matching\Actions\MatchDemandToExistingCommutesAction;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
use Illuminate\Console\Command;

/**
 * Matches every active saved request against the commutes running now. Run once after deploying the
 * fix that added matching on save — requests saved before it were never matched to commutes that
 * already existed. Safe to repeat: one row per (request, commute).
 */
final class MatchExistingDemands extends Command
{
    protected $signature = 'demands:match-existing';

    protected $description = 'Match every active saved request against the commutes already published.';

    public function handle(MatchDemandToExistingCommutesAction $action): int
    {
        $recorded = 0;

        CommuteDemand::query()
            ->where('status', CommuteDemandStatus::Active->value)
            ->with('passenger')
            ->chunkById(100, function ($demands) use ($action, &$recorded): void {
                foreach ($demands as $demand) {
                    $recorded += $action->execute($demand);
                }
            });

        $this->info("Recorded {$recorded} new matches.");

        return self::SUCCESS;
    }
}
