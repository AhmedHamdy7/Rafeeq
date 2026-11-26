<?php

namespace App\Console\Commands;

use App\Domains\Commute\Actions\RefreshCommuteRoadAction;
use App\Domains\Commute\Models\CommuteOffer;
use Illuminate\Console\Command;

final class RefreshCommuteRoads extends Command
{
    protected $signature = 'commutes:refresh-roads {--limit=500}';

    protected $description = 'Fetch the road again for live commutes whose stored road is older than the refresh period.';

    public function handle(RefreshCommuteRoadAction $action): int
    {
        $refreshed = 0;
        $failed = 0;

        RefreshCommuteRoadAction::due()
            ->orderBy('route_fetched_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->each(function (CommuteOffer $offer) use ($action, &$refreshed, &$failed): void {
                $action->execute($offer) ? $refreshed++ : $failed++;
            });

        $this->info("Refreshed {$refreshed} commute roads, {$failed} kept as they were.");

        return self::SUCCESS;
    }
}
