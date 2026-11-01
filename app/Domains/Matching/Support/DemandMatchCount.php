<?php

namespace App\Domains\Matching\Support;

use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Matching\Models\MatchNotification;

/**
 * "3 matching commutes this week" on a demand card (DISCOVER-10, BOOK-06).
 *
 * Counted as: the different commutes the platform matched to this demand that are still published
 * and run at least once in the next {@see DAYS} days. Not the number of notifications — a commute
 * matched twice is one commute — and not every match ever found, because a commute that was
 * archived last month is not something she can ride this week.
 *
 * One grouped query for a whole list of demands, so the Demands tab costs the same with one demand
 * or ten.
 */
final class DemandMatchCount
{
    /** The design's "this week": the next seven days from now. */
    public const int DAYS = 7;

    /**
     * @param  array<int, string>  $demandIds
     * @return array<string, int> demand id → count; a demand with none is absent
     */
    public static function for(array $demandIds): array
    {
        if ($demandIds === []) {
            return [];
        }

        $rows = MatchNotification::query()
            ->whereIn('commute_demand_id', $demandIds)
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('status', CommuteOfferStatus::Published->value)
                ->select('id'))
            ->whereIn('commute_offer_id', ScheduledTrip::query()
                ->where('status', ScheduledTripStatus::Scheduled->value)
                ->whereBetween('departure_at', [now(), now()->addDays(self::DAYS)])
                ->select('commute_offer_id'))
            ->groupBy('commute_demand_id')
            ->selectRaw('commute_demand_id, count(distinct commute_offer_id) as matched')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->commute_demand_id] = (int) $row->matched;
        }

        return $counts;
    }
}
