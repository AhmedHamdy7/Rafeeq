<?php

namespace App\Domains\Matching\Actions;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Geo\Actions\SearchPlacesAction;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Jobs\MatchNewDemand;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Support\PlaceLabels;
use App\Domains\Matching\Support\SearchCriteria;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

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
    public function __construct(private SearchPlacesAction $places) {}

    public function execute(User $passenger, SearchCriteria $criteria, ?PlaceLabels $labels = null): CommuteDemand
    {
        $this->assertNotDuplicate($passenger->id, $criteria);

        $demand = new CommuteDemand;

        $demand->fill(['passenger_user_id' => $passenger->id]);
        $this->fillCriteria($demand, $criteria, $labels);

        // Not fillable: a request must not be able to set its own status, and the
        // expiry is a platform policy rather than the passenger's choice.
        $demand->status = CommuteDemandStatus::Active->value;
        $demand->expires_at = self::freshExpiry();

        $demand->save();

        // Against the commutes already running — see MatchDemandToExistingCommutesAction.
        DB::afterCommit(fn () => self::matchNow($demand->id));

        return $demand;
    }

    /**
     * Edit or pause a saved request (S-4, BOOK-06, decision C12).
     *
     * A new set of criteria replaces the old one and is matched again against the commutes already
     * running. Pausing keeps everything and stops notifications; resuming matches it again at once,
     * because she paused it to stop being told, not to miss what was published meanwhile.
     */
    public function update(CommuteDemand $demand, ?SearchCriteria $criteria, ?PlaceLabels $labels, ?bool $paused): CommuteDemand
    {
        $rematch = false;

        if ($criteria !== null) {
            $this->assertNotDuplicate($demand->passenger_user_id, $criteria, $demand->id);
            $this->fillCriteria($demand, $criteria, $labels);
            $rematch = true;

            if ($demand->status === CommuteDemandStatus::Expired) {
                $demand->status = CommuteDemandStatus::Active->value;
            }

            $demand->expires_at = self::freshExpiry();
        }

        if ($paused === true && $demand->status === CommuteDemandStatus::Active) {
            $demand->status = CommuteDemandStatus::Paused->value;
        }

        if ($paused === false && in_array($demand->status, [CommuteDemandStatus::Paused, CommuteDemandStatus::Expired], true)) {
            $demand->status = CommuteDemandStatus::Active->value;
            $demand->expires_at = self::freshExpiry();
            $rematch = true;
        }

        $demand->save();

        if ($rematch && $demand->status === CommuteDemandStatus::Active) {
            DB::afterCommit(fn () => self::matchNow($demand->id));
        }

        return $demand->refresh();
    }

    private static function freshExpiry(): CarbonInterface
    {
        return now()->addDays((int) config('rafeeq.matching.demand_expiry_days'));
    }

    private static function matchNow(string $demandId): void
    {
        try {
            MatchNewDemand::dispatchSync($demandId);
        } catch (Throwable $e) {
            report($e);
            MatchNewDemand::dispatch($demandId);
        }
    }

    private function assertNotDuplicate(string $passengerId, SearchCriteria $criteria, ?string $except = null): void
    {
        $existing = CommuteDemand::query()
            ->where('passenger_user_id', $passengerId)
            ->whereIn('status', [CommuteDemandStatus::Active->value, CommuteDemandStatus::Paused->value, CommuteDemandStatus::Expired->value])
            ->where('days_mask', $criteria->days->value)
            ->where('preferred_arrival_start', $criteria->arrivalWindowStart)
            ->where('preferred_arrival_end', $criteria->arrivalWindowEnd)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->get(['id', 'origin_lat', 'origin_lng', 'dest_lat', 'dest_lng'])
            ->first(fn (CommuteDemand $demand): bool => round((float) $demand->origin_lat, 3) === round($criteria->origin->lat, 3)
                && round((float) $demand->origin_lng, 3) === round($criteria->origin->lng, 3)
                && round((float) $demand->dest_lat, 3) === round($criteria->destination->lat, 3)
                && round((float) $demand->dest_lng, 3) === round($criteria->destination->lng, 3));

        if ($existing !== null) {
            throw DomainException::of(ErrorCode::DemandExists, fields: ['demandId' => [(string) $existing->id]]);
        }
    }

    public function cancel(CommuteDemand $demand): CommuteDemand
    {
        $demand->status = CommuteDemandStatus::Cancelled->value;
        $demand->save();

        return $demand;
    }

    /**
     * The criteria columns, shared by creating and editing so the two can never drift.
     *
     * The labels are resolved here, so an end sent as bare coordinates is named after the nearest
     * catalogue place (DISCOVER-9, -12) — every demand screen and Home's suggestion row name the
     * route, and none of them can geocode.
     */
    private function fillCriteria(CommuteDemand $demand, SearchCriteria $criteria, ?PlaceLabels $labels): void
    {
        $labels = ($labels ?? new PlaceLabels)->resolved($this->places, $criteria->origin, $criteria->destination);

        $demand->fill([
            'origin_point' => $criteria->origin,
            'destination_point' => $criteria->destination,
            'origin_lat' => $criteria->origin->lat,
            'origin_lng' => $criteria->origin->lng,
            'dest_lat' => $criteria->destination->lat,
            'dest_lng' => $criteria->destination->lng,
            'origin_label' => $labels->originLabel,
            'origin_place_id' => $labels->originPlaceId,
            'destination_label' => $labels->destinationLabel,
            'destination_place_id' => $labels->destinationPlaceId,
            'commute_type' => $criteria->days->value === 0 ? 'one_time' : 'recurring',
            'days_mask' => $criteria->days->value,
            'preferred_arrival_start' => $criteria->arrivalWindowStart,
            'preferred_arrival_end' => $criteria->arrivalWindowEnd,
            'max_walk_minutes' => $criteria->maxWalk->minutes,
            'max_detour_minutes' => $criteria->maxDetourMinutes,
            'flexibility_minutes' => $criteria->flexibilityMinutes,
            'wants_return_trip' => $criteria->wantsReturnTrip,
            'audience_preference' => ($criteria->audiencePreference?->value)
                ?? CommuteAudience::AnyVerified->value,

            /*
             * Her preferences, the same as the search's own (DISCOVER-9, BOOK-06): the Women-only
             * filter, the comfort chips and the Comfort car. Kept, so the alerts this demand sends
             * ask the question she asked — they were dropped before, and a demand for a
             * women-only commute was alerted about every commute on the corridor.
             */
            'women_only' => $criteria->womenOnly,
            'required_rules' => $criteria->requiredRules === [] ? null : array_values($criteria->requiredRules),
            'vehicle_tier' => $criteria->tier?->value,
        ]);
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
