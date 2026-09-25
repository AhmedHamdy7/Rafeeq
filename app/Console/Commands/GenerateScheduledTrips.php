<?php

namespace App\Console\Commands;

use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Commute\Actions\GenerateScheduledTripsAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Rolls the generation horizon forward one day, every day.
 *
 * This is the job that makes recurring commutes work. Publishing generates the
 * first 30 days; without this, the 31st day never exists and a commute silently
 * stops being bookable a month after it was created — the kind of failure nobody
 * notices until a passenger cannot book next month.
 *
 * It also archives schedules that have run their course, because Chapter 4 lists
 * "recurring end date reached → archive automatically" as an edge case, and
 * because a commute whose last day has passed should stop appearing anywhere.
 *
 * Safe to run twice: generation is idempotent by (offer, date), enforced by a
 * unique index as well as by the query.
 */
final class GenerateScheduledTrips extends Command
{
    protected $signature = 'commutes:generate-trips
        {--offer= : Limit to one commute offer, for investigating a single case}';

    protected $description = 'Extend the rolling scheduled-trip horizon and archive finished commutes.';

    public function handle(
        GenerateScheduledTripsAction $generate,
        ChangeCommuteStatusAction $changeStatus,
    ): int {
        $created = 0;
        $archived = 0;

        CommuteOffer::query()
            ->where('status', CommuteOfferStatus::Published->value)
            ->when($this->option('offer'), fn ($query, $id) => $query->whereKey($id))
            ->with('schedule')
            // Chunked rather than loaded: this walks every published commute on
            // the platform, and the whole point of the rolling horizon is not to
            // hold the fleet's worth of data at once.
            ->chunkById(200, function ($offers) use ($generate, $changeStatus, &$created, &$archived): void {
                foreach ($offers as $offer) {
                    if ($offer->schedule === null) {
                        continue;
                    }

                    if ($offer->schedule->end_date->lessThan(CarbonImmutable::today())) {
                        $changeStatus->archive($offer, 'schedule_ended');
                        $archived++;

                        continue;
                    }

                    $created += $generate->execute($offer);
                }
            });

        $this->info("Generated {$created} scheduled trips; archived {$archived} finished commutes.");

        return self::SUCCESS;
    }
}
