<?php

namespace App\Domains\Matching\Actions;

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use App\Domains\Matching\Support\MatchResult;
use App\Domains\Matching\Support\SearchCriteria;
use App\Domains\Shared\ValueObjects\WalkTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * When a commute is published, tells the passengers who were waiting for it
 * (Chapter 5: "when a future commute matches, RAFEEQ automatically sends a
 * notification").
 *
 * The direction matters. This runs from the OFFER towards the demands, never the
 * other way: a driver never sees who was waiting, only that someone joined. The
 * demand stays secret, and the passenger gets told.
 *
 * Each demand is turned back into the search it came from and run through the
 * real engine. Reimplementing a cheaper "close enough" comparison here would
 * eventually notify people about commutes the search itself would not show them —
 * two answers to the same question is one too many.
 */
final readonly class NotifyMatchingDemandsAction
{
    public function __construct(private SearchCommutesAction $search) {}

    /**
     * @return int how many passengers were notified
     */
    public function execute(CommuteOffer $offer): int
    {
        $threshold = (int) config('rafeeq.matching.notification_score_threshold');
        $cooldown = (int) config('rafeeq.matching.notification_cooldown_hours');

        $notified = 0;

        foreach ($this->candidateDemands($offer, $cooldown) as $demand) {
            $result = $this->bestMatchFor($demand, $offer);

            if ($result === null || $result->score->total() < $threshold) {
                continue;
            }

            // The unique index on (demand, offer) is what actually guarantees one
            // notification per pair — this check is the polite version, the index
            // is the one that holds under a race.
            if (MatchNotification::query()
                ->where('commute_demand_id', $demand->id)
                ->where('commute_offer_id', $offer->id)
                ->exists()) {
                continue;
            }

            DB::transaction(function () use ($demand, $offer, $result): void {
                MatchNotification::create([
                    'commute_demand_id' => $demand->id,
                    'commute_offer_id' => $offer->id,
                    // The score AT THE TIME: the commute may change later, and
                    // this records why the interruption was justified.
                    'score' => $result->score->total(),
                ]);

                // Stamped so the cooldown holds even when several commutes are
                // published in the same minute.
                $demand->forceFill(['last_notified_at' => now()])->save();
            });

            $notified++;
        }

        return $notified;
    }

    /**
     * Demands that might care, narrowed in SQL first: still active, not already
     * notified recently, and overlapping this commute's days.
     *
     * @return Collection<int, CommuteDemand>
     */
    private function candidateDemands(CommuteOffer $offer, int $cooldownHours)
    {
        $schedule = $offer->schedule;

        return CommuteDemand::query()
            ->with('passenger')
            ->where('status', CommuteDemandStatus::Active->value)
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query
                ->whereNull('last_notified_at')
                ->orWhere('last_notified_at', '<=', now()->subHours($cooldownHours)))
            ->when($schedule !== null, fn ($query) => $query
                ->whereRaw('(days_mask & ?) > 0', [$schedule->days_mask]))
            ->get();
    }

    /**
     * Runs the demand back through the real search engine, and keeps the result
     * only if it is THIS commute — a demand may well match something else, but
     * that is not what was just published.
     */
    private function bestMatchFor(CommuteDemand $demand, CommuteOffer $offer): ?MatchResult
    {
        $criteria = new SearchCriteria(
            origin: $demand->origin_point,
            destination: $demand->destination_point,
            days: $demand->daysMask(),
            arrivalWindowStart: $demand->preferred_arrival_start,
            arrivalWindowEnd: $demand->preferred_arrival_end,
            maxWalk: WalkTime::fromMinutes($demand->max_walk_minutes),
            maxDetourMinutes: $demand->max_detour_minutes,
            audiencePreference: $demand->audience_preference,
            budgetPerSeatPiastres: $demand->budget_monthly_piastres,
        );

        foreach ($this->search->execute($demand->passenger, $criteria) as $result) {
            if ($result->offer->id === $offer->id) {
                return $result;
            }
        }

        return null;
    }
}
