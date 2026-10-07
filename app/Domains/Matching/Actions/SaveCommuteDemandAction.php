<?php

namespace App\Domains\Matching\Actions;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Jobs\MatchNewDemand;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Support\SearchCriteria;
use Illuminate\Support\Facades\DB;

/**
 * Saves what a passenger was looking for when nothing matched (Chapter 5's
 * "Save this commute request?").
 *
 * 🔒 The row this creates is SECRET. No endpoint may ever return it, or anything
 * derived from it, to a driver. Chapter 5 states it twice — "passenger demand is
 * private", "drivers never browse passenger demands" — and it is the line between
 * Rafeeq and an auction on passengers. A driver who could browse demands would be
 * shopping for people; instead, a driver publishes their own journey and the
 * platform tells the passenger.
 *
 * It expires. A demand nobody matched for months is no longer what that person
 * wants, and notifying them about it would be worse than silence.
 */
final readonly class SaveCommuteDemandAction
{
    public function execute(User $passenger, SearchCriteria $criteria, ?array $labels = null): CommuteDemand
    {
        $demand = new CommuteDemand;

        $demand->fill([
            'passenger_user_id' => $passenger->id,
            'origin_point' => $criteria->origin,
            'destination_point' => $criteria->destination,
            'origin_lat' => $criteria->origin->lat,
            'origin_lng' => $criteria->origin->lng,
            'dest_lat' => $criteria->destination->lat,
            'dest_lng' => $criteria->destination->lng,
            'origin_label' => $labels['origin'] ?? null,
            'destination_label' => $labels['destination'] ?? null,
            'commute_type' => $criteria->days->value === 0 ? 'one_time' : 'recurring',
            'days_mask' => $criteria->days->value,
            'preferred_arrival_start' => $criteria->arrivalWindowStart,
            'preferred_arrival_end' => $criteria->arrivalWindowEnd,
            'max_walk_minutes' => $criteria->maxWalk->minutes,
            'max_detour_minutes' => $criteria->maxDetourMinutes,
            'budget_monthly_piastres' => $criteria->budgetMonthlyPiastres,
            'flexibility_minutes' => $criteria->flexibilityMinutes,
            'wants_return_trip' => $criteria->wantsReturnTrip,
            'audience_preference' => ($criteria->audiencePreference?->value)
                ?? CommuteAudience::AnyVerified->value,
        ]);

        // Not fillable: a request must not be able to set its own status, and the
        // expiry is a platform policy rather than the passenger's choice.
        $demand->status = CommuteDemandStatus::Active->value;
        $demand->expires_at = now()->addDays((int) config('rafeeq.matching.demand_expiry_days'));

        $demand->save();

        // Against the commutes already running — see MatchDemandToExistingCommutesAction.
        DB::afterCommit(fn () => MatchNewDemand::dispatch($demand->id));

        return $demand;
    }

    public function cancel(CommuteDemand $demand): CommuteDemand
    {
        $demand->status = CommuteDemandStatus::Cancelled->value;
        $demand->save();

        return $demand;
    }

    /**
     * Demands that have run out of time. Swept rather than checked lazily so a
     * passenger's stale request stops being matched even if they never open the
     * app again.
     */
    public static function expireOverdue(): int
    {
        return CommuteDemand::query()
            ->where('status', CommuteDemandStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => CommuteDemandStatus::Expired->value]);
    }
}
