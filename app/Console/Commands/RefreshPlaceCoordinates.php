<?php

namespace App\Console\Commands;

use App\Domains\Geo\Actions\RefreshPlaceCoordinatesAction;
use Illuminate\Console\Command;

final class RefreshPlaceCoordinates extends Command
{
    protected $signature = 'places:refresh-coordinates {--limit=}';

    protected $description = 'Refresh or drop the Google coordinates of place suggestions before they pass the 30-day limit.';

    public function handle(RefreshPlaceCoordinatesAction $action): int
    {
        $limit = max(1, (int) ($this->option('limit') ?? config('rafeeq.places.refresh_batch')));

        $result = $action->execute($limit);

        $this->info("Refreshed {$result['refreshed']} place coordinates, dropped {$result['dropped']}.");

        return self::SUCCESS;
    }
}
