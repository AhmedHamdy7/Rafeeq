<?php

namespace App\Domains\Trip\Support;

use App\Domains\Trip\ValueObjects\TripPosition;
use Illuminate\Support\Facades\Cache;

/**
 * Where a car is RIGHT NOW, and the points waiting to be written down.
 *
 * 🔴 Pitfall #46 is what this class exists for: "500 drivers × every 5 seconds = 100 writes
 * a second. The database will fall over." The answer the Bible gives is to split the two
 * jobs a position does, because they have nothing in common:
 *
 * - **Being current** — a passenger watching a map needs the newest point and only the
 *   newest, within a second or two. That is one small value per run, read constantly and
 *   overwritten constantly, which is a cache and not a table.
 * - **Being a record** — a dispute, months later, needs the whole trail. That is an
 *   append-only history nobody reads in the moment, which is a table and not a cache, and
 *   can be written in batches.
 *
 * Writing both to `trip_locations` on every ping is what the pitfall describes. Keeping both
 * in the cache would lose the evidence the first time Redis restarts.
 *
 * Behind `Cache` rather than `Redis` directly: the store is configurable, tests run against
 * the database driver, and nothing here needs a Redis-only operation. The Bible names Redis
 * because that is what production should run, not because the code should require it.
 */
final class LiveLocationStore
{
    /**
     * How long the latest position stays readable after it arrives.
     *
     * Short on purpose. A run's newest position is worthless once it stops being newest, and
     * a stale dot on a map is worse than none — a passenger who sees a car that stopped
     * reporting twenty minutes ago will walk towards it. When this expires, the endpoint
     * says there is no current position and `lastLocationAt` says how long the silence has
     * been going on.
     */
    private const int CURRENT_TTL_SECONDS = 300;

    /**
     * How long a pending batch may sit before the flush must have taken it.
     *
     * Generously longer than the flush interval: this is a safety net against a scheduler
     * that missed a beat, not the mechanism. If it ever actually expires, points are lost,
     * which is why the flush runs every thirty seconds and not every ten minutes.
     */
    private const int BUFFER_TTL_SECONDS = 3600;

    public function putCurrent(string $sessionId, TripPosition $position): void
    {
        Cache::put(self::currentKey($sessionId), $position->toArray(), self::CURRENT_TTL_SECONDS);
    }

    public function current(string $sessionId): ?TripPosition
    {
        $stored = Cache::get(self::currentKey($sessionId));

        return is_array($stored) ? TripPosition::fromArray($stored) : null;
    }

    public function forgetCurrent(string $sessionId): void
    {
        Cache::forget(self::currentKey($sessionId));
    }

    /**
     * Queues points for the next bulk insert.
     *
     * 🔴 The session id is also recorded in a set of "sessions with pending points", because
     * the flush has to find the buffers without scanning the cache — `Cache::get` on a key
     * you can name is cheap on every driver, and a key scan is a Redis operation this
     * deliberately avoids depending on.
     *
     * @param  array<int, TripPosition>  $positions
     */
    public function buffer(string $sessionId, array $positions): void
    {
        if ($positions === []) {
            return;
        }

        $pending = Cache::get(self::bufferKey($sessionId), []);

        foreach ($positions as $position) {
            $pending[] = $position->toArray();
        }

        Cache::put(self::bufferKey($sessionId), $pending, self::BUFFER_TTL_SECONDS);

        $sessions = Cache::get(self::INDEX_KEY, []);

        if (! in_array($sessionId, $sessions, true)) {
            $sessions[] = $sessionId;
            Cache::put(self::INDEX_KEY, $sessions, self::BUFFER_TTL_SECONDS);
        }
    }

    /**
     * Takes everything buffered for one run and clears it.
     *
     * Read-then-forget rather than read-then-delete-what-was-read: a point that arrives
     * between the two is lost either way, and the alternative is holding a lock around every
     * GPS ping on the platform. Losing one position out of a five-second stream, in a race
     * that needs two writes in the same millisecond, is a trade the trail can carry — losing
     * throughput on every ping is not.
     *
     * @return array<int, TripPosition>
     */
    public function takeBuffered(string $sessionId): array
    {
        $pending = Cache::pull(self::bufferKey($sessionId), []);

        $positions = [];

        foreach ($pending as $row) {
            $positions[] = TripPosition::fromArray($row);
        }

        return $positions;
    }

    /**
     * Which runs have points waiting.
     *
     * @return array<int, string>
     */
    public function pendingSessions(): array
    {
        return Cache::get(self::INDEX_KEY, []);
    }

    public function forgetPending(string $sessionId): void
    {
        Cache::forget(self::bufferKey($sessionId));

        Cache::put(
            self::INDEX_KEY,
            array_values(array_diff(Cache::get(self::INDEX_KEY, []), [$sessionId])),
            self::BUFFER_TTL_SECONDS,
        );
    }

    private const string INDEX_KEY = 'trip:locations:pending';

    private static function currentKey(string $sessionId): string
    {
        return "trip:{$sessionId}:position";
    }

    private static function bufferKey(string $sessionId): string
    {
        return "trip:{$sessionId}:positions:pending";
    }
}
