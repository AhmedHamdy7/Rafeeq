<?php

namespace App\Domains\Matching\Jobs;

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Matching\Actions\NotifyMatchingDemandsAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Matching waiting passengers against a newly published commute, off the request.
 *
 * Queued because it runs the full search engine once per candidate demand, and a
 * driver pressing Publish should not wait for that — nor should their publish
 * fail because the matching did. Publishing is the driver's action; telling
 * passengers is a consequence of it.
 *
 * The offer is passed by id rather than as a model so the job reads current state
 * when it runs. A serialized model could describe an offer that was paused in the
 * seconds between dispatch and execution, and this would then notify people about
 * a commute that no longer accepts anyone.
 */
final class NotifyMatchingDemands implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $commuteOfferId) {}

    public function handle(NotifyMatchingDemandsAction $action): void
    {
        $offer = CommuteOffer::query()
            ->with(['schedule', 'locations', 'rules', 'driverProfile'])
            ->whereKey($this->commuteOfferId)
            ->first();

        // Gone or no longer published: nothing to tell anyone about. Not an
        // error — a driver is free to pause a commute moments after publishing it.
        if ($offer === null || ! $offer->isPublished()) {
            return;
        }

        $action->execute($offer);
    }
}
