<?php

namespace App\Console\Commands;

use App\Domains\Trip\Models\TripLocation;
use App\Domains\Trip\Support\LiveLocationStore;
use App\Domains\Trip\Support\TripSettings;
use App\Domains\Trip\ValueObjects\TripPosition;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Writes the buffered GPS points down, in batches.
 *
 * 🔴 This command IS the answer to pitfall #46. Five hundred drivers pinging every five
 * seconds is a hundred inserts a second if each ping writes a row; the same traffic is two
 * bulk inserts a minute if the pings are buffered and flushed. Nothing else about the
 * feature changes — the passenger's map is fed from the cache and moves immediately either
 * way. This only decides how the permanent record gets written.
 *
 * 🔒 `purge_after` is set here, on every row, as it is created. The retention rule (ERD
 * §23.4, 90 days) is a legal boundary around a minute-by-minute record of where real people
 * were, and a boundary enforced by a query that has to remember to calculate it is not
 * enforced. Stamped on the row, the deletion job is a `where purge_after < today` and cannot
 * get it wrong.
 *
 * ⚠️ Without a cron entry running `php artisan schedule:run`, the buffers fill, expire after
 * an hour, and every trail is silently lost. See DEPLOYMENT.md.
 */
final class FlushTripLocations extends Command
{
    protected $signature = 'trips:flush-locations';

    protected $description = 'Bulk-insert buffered GPS positions into trip_locations and stamp their retention date.';

    /**
     * How many rows go into one insert statement.
     *
     * Bounded because a single statement with fifty thousand rows is its own outage: it
     * exceeds the packet limit, holds the table longer than anything else needs it, and
     * fails as one unit so a single bad batch loses every trail in it.
     */
    private const int CHUNK = 500;

    public function handle(LiveLocationStore $store): int
    {
        $sessions = $store->pendingSessions();

        if ($sessions === []) {
            return self::SUCCESS;
        }

        $purgeAfter = now()->addDays(TripSettings::locationRetentionDays())->toDateString();
        $written = 0;

        foreach ($sessions as $sessionId) {
            $positions = $store->takeBuffered($sessionId);

            if ($positions === []) {
                // Nothing left — the index still named it, so take it off the list.
                $store->forgetPending($sessionId);

                continue;
            }

            foreach (array_chunk($positions, self::CHUNK) as $chunk) {
                TripLocation::query()->insert(
                    array_map(
                        fn (TripPosition $position) => [
                            // Generated here: `insert()` bypasses the model, so `HasUlids`
                            // never runs and the rows would arrive with no primary key.
                            'id' => (string) Str::ulid(),
                            'trip_session_id' => $sessionId,
                            'lat' => $position->lat,
                            'lng' => $position->lng,
                            'accuracy_meters' => $position->accuracyMeters,
                            'speed_kmh' => $position->speedKmh,
                            // The DEVICE's clock, which is the whole point of the column.
                            'recorded_at' => $position->recordedAt,
                            'purge_after' => $purgeAfter,
                            'created_at' => now(),
                        ],
                        $chunk,
                    )
                );

                $written += count($chunk);
            }

            $store->forgetPending($sessionId);
        }

        $this->info("Wrote {$written} positions across ".count($sessions).' runs.');

        return self::SUCCESS;
    }
}
