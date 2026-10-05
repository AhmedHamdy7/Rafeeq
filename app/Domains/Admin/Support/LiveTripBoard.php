<?php

namespace App\Domains\Admin\Support;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\Support\TripSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The LIVE TRIPS board: every run that has started and not finished.
 *
 * 🔴 Sorted so that the car somebody should be looking at is at the top, not so that the
 * list is tidy. In order:
 *
 * 1. a run with a live SOS on it — somebody in that car has asked for help;
 * 2. a run that was declared an emergency;
 * 3. a run that left the corridor (`deviation_detected_at`, Phase 9);
 * 4. a run whose phone has gone quiet;
 * 5. everything else, soonest departure first.
 *
 * A board ordered by departure alone puts the one car that matters on page three during
 * the morning peak, and nobody on a busy desk reads page three.
 *
 * What it does NOT show: passengers' phone numbers, their pickup points, or anybody's
 * home. The board answers "which cars are out and is anything wrong"; it is not a map of
 * where members live, and a dashboard is exactly the sort of screen that gets
 * photographed.
 */
final class LiveTripBoard
{
    /**
     * @return list<TripSessionStatus>
     */
    public static function liveStatuses(): array
    {
        return [
            TripSessionStatus::Preparing,
            TripSessionStatus::EnRoute,
            TripSessionStatus::AtPickup,
            TripSessionStatus::InProgress,
            /*
             * Included although it is not "underway": an interrupted run stays in this state
             * until somebody decides what happened, so dropping it from the board would make
             * the one run that most needs a person disappear from the only screen that shows
             * runs.
             */
            TripSessionStatus::Emergency,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, TripSession>
     */
    public static function page(int $perPage, bool $flaggedOnly = false, bool $womenOnly = false): LengthAwarePaginator
    {
        $query = self::query()
            ->withExists(['safetyEvents as has_live_alert' => fn (Builder $events) => self::liveAlerts($events)])
            ->with([
                'scheduledTrip.commuteOffer.locations',
                'scheduledTrip.commuteOffer.vehicle',
                'scheduledTrip.commuteOffer.driverProfile.user',
            ]);

        if ($flaggedOnly) {
            $query->where(fn (Builder $flagged) => $flagged
                ->whereHas('safetyEvents', fn (Builder $events) => self::liveAlerts($events))
                ->orWhere('current_status', TripSessionStatus::Emergency->value)
                ->orWhereNotNull('deviation_detected_at')
                ->orWhere(fn (Builder $silent) => self::silent($silent)));
        }

        if ($womenOnly) {
            $query->whereHas('scheduledTrip.commuteOffer', fn (Builder $offer) => $offer
                ->where('audience', CommuteAudience::WomenOnly->value));
        }

        $silentSince = now()->subSeconds(TripSettings::gpsSilenceAlertSeconds());

        return $query
            ->orderByDesc('has_live_alert')
            ->orderByRaw('current_status = ? DESC', [TripSessionStatus::Emergency->value])
            ->orderByRaw('deviation_detected_at IS NULL')
            ->orderByRaw(
                'CASE WHEN current_status IN (?, ?, ?) AND COALESCE(last_location_at, departed_at, started_at) < ? THEN 0 ELSE 1 END',
                [...self::underwayValues(), $silentSince],
            )
            ->orderBy(ScheduledTrip::query()
                ->select('departure_at')
                ->whereColumn('scheduled_trips.id', 'trip_sessions.scheduled_trip_id'))
            ->orderBy('id')
            ->paginate($perPage);
    }

    public static function count(): int
    {
        return self::query()->count();
    }

    /**
     * Whether this run's phone has gone quiet for longer than the board tolerates.
     *
     * Only while it is actually on the road. A run still `preparing` has not been asked for
     * positions yet, and calling it silent would flag every car whose driver opened the app
     * early.
     *
     * Measured from the last position when there is one, and otherwise from when the car
     * moved — a run that set off ten minutes ago and has never sent a single point is the
     * most silent of all, not the least.
     */
    public static function isSilent(TripSession $session): bool
    {
        if (! $session->current_status->isUnderway()) {
            return false;
        }

        $lastHeard = $session->last_location_at ?? $session->departed_at ?? $session->started_at;

        return $lastHeard !== null
            && $lastHeard->lt(now()->subSeconds(TripSettings::gpsSilenceAlertSeconds()));
    }

    /**
     * @return Builder<TripSession>
     */
    private static function query(): Builder
    {
        return TripSession::query()->whereIn(
            'current_status',
            array_map(fn (TripSessionStatus $status) => $status->value, self::liveStatuses()),
        );
    }

    /**
     * The SQL twin of {@see isSilent()}. Two places, one rule — the test pins them together.
     */
    private static function silent(Builder $query): void
    {
        $query
            ->whereIn('current_status', self::underwayValues())
            ->whereRaw(
                'COALESCE(last_location_at, departed_at, started_at) < ?',
                [now()->subSeconds(TripSettings::gpsSilenceAlertSeconds())],
            );
    }

    private static function liveAlerts(Builder $events): void
    {
        $events
            ->whereIn('type', [SafetyEventType::Sos->value, SafetyEventType::DiscreetAlert->value])
            ->whereHas('sosEvent', fn (Builder $sos) => $sos
                ->whereNull('cancelled_at')
                ->whereNull('resolution'));
    }

    /**
     * @return list<string>
     */
    private static function underwayValues(): array
    {
        return [
            TripSessionStatus::EnRoute->value,
            TripSessionStatus::AtPickup->value,
            TripSessionStatus::InProgress->value,
        ];
    }
}
