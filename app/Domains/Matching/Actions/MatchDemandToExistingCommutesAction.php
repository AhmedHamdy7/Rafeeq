<?php

namespace App\Domains\Matching\Actions;

use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;

/**
 * The other half of matching: a NEW request against commutes that already exist.
 *
 * 🔴 Until this existed, matching ran in one direction only — when a commute was published, against
 * the requests waiting for it. A passenger who saved a request on a corridor with commutes already
 * running was never matched to any of them, so Home's "top matches" (read from `match_notifications`)
 * stayed empty for exactly the person most likely to want one.
 *
 * Records the matches; does NOT send a "match found" notice. The passenger has just been shown these
 * same commutes by the search they saved from — a notification about them would be the platform
 * repeating itself. Notices stay for commutes published later, which they have not seen.
 *
 * Idempotent: one row per (request, commute), guarded by the table's unique index.
 */
final readonly class MatchDemandToExistingCommutesAction
{
    public function __construct(private SearchCommutesAction $search) {}

    /**
     * @return int how many new matches were recorded
     */
    public function execute(CommuteDemand $demand): int
    {
        if ($demand->status !== CommuteDemandStatus::Active || ($demand->expires_at !== null && $demand->expires_at->isPast())) {
            return 0;
        }

        $threshold = (int) config('rafeeq.matching.notification_score_threshold');
        $recorded = 0;

        foreach ($this->search->execute($demand->passenger, NotifyMatchingDemandsAction::criteriaFor($demand)) as $result) {
            if ($result->score->total() < $threshold) {
                continue;
            }

            $recorded += MatchNotification::query()->insertOrIgnore([
                'id' => (new MatchNotification)->newUniqueId(),
                'commute_demand_id' => $demand->id,
                'commute_offer_id' => $result->offer->id,
                'score' => $result->score->total(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $recorded;
    }
}
