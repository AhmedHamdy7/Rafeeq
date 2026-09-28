<?php

namespace App\Console\Commands;

use App\Domains\Trip\Models\TripLocation;
use Illuminate\Console\Command;

/**
 * 🔒 Deletes GPS trails whose retention date has passed (ERD §23.4: 90 days).
 *
 * The one command in this application whose job is to destroy data, and the reason is worth
 * stating plainly: `trip_locations` is a minute-by-minute record of where real people were,
 * and it exists for exactly one purpose — a no-show dispute has no other evidence. The
 * dispute window is 24 hours and a support case takes days. Past ninety days the record has
 * no purpose left, and a record with no purpose is a liability: it is something to be
 * subpoenaed, breached, or quietly repurposed, and none of those were consented to.
 *
 * So this is not housekeeping. Keeping it running is part of what makes collecting the data
 * defensible in the first place.
 *
 * Deletes in chunks rather than one statement: 500 drivers × 600 points × 90 days is tens of
 * millions of rows, and a single `DELETE` over that holds the table long enough to stall
 * every live run on the platform.
 *
 * ⚠️ Without a cron entry running `php artisan schedule:run`, nothing is ever deleted and the
 * retention promise is not kept. See DEPLOYMENT.md.
 */
final class PurgeTripLocations extends Command
{
    protected $signature = 'trips:purge-locations';

    protected $description = 'Delete GPS positions past their retention date (ERD §23.4).';

    private const int CHUNK = 2000;

    public function handle(): int
    {
        $deleted = 0;

        do {
            $batch = TripLocation::query()
                ->whereNotNull('purge_after')
                ->whereDate('purge_after', '<=', now()->toDateString())
                ->limit(self::CHUNK)
                ->delete();

            $deleted += $batch;
        } while ($batch === self::CHUNK);

        /*
         * 🔴 Rows with no `purge_after` are reported rather than deleted or ignored.
         *
         * The column is nullable, so a row can exist without one — written by a migration, a
         * fixture, or a future code path that forgot. Deleting them would destroy evidence on
         * a guess about its age; leaving them silently would mean the retention promise has a
         * hole nobody is looking at. Saying so is the only honest option.
         */
        $undated = TripLocation::query()->whereNull('purge_after')->count();

        if ($undated > 0) {
            $this->warn("{$undated} positions have no retention date and were left alone. They are outside the 90-day promise — find what wrote them.");
        }

        $this->info("Deleted {$deleted} expired positions.");

        return self::SUCCESS;
    }
}
