<?php

namespace App\Domains\Commute\Jobs;

use App\Domains\Commute\Actions\RecomputeDepartureAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-times a day after its riders changed (C-2). Queued and dispatched after commit, so a routing
 * provider's latency never sits inside the row lock that confirmed or cancelled a seat.
 */
final class RecomputeTripDeparture implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $scheduledTripId) {}

    public function handle(RecomputeDepartureAction $action): void
    {
        $action->execute($this->scheduledTripId);
    }
}
