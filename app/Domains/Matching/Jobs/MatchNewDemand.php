<?php

namespace App\Domains\Matching\Jobs;

use App\Domains\Matching\Actions\MatchDemandToExistingCommutesAction;
use App\Domains\Matching\Models\CommuteDemand;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Matches a just-saved request against the commutes already published, off the request — the save
 * answers at once, and a slow search must not be able to fail it.
 */
final class MatchNewDemand implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $commuteDemandId) {}

    public function handle(MatchDemandToExistingCommutesAction $action): void
    {
        $demand = CommuteDemand::query()->with('passenger')->whereKey($this->commuteDemandId)->first();

        if ($demand !== null) {
            $action->execute($demand);
        }
    }
}
