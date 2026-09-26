<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\DB;

/**
 * Moving a run through the states between "started" and "finished".
 *
 * Chapter 8's sequence: preparing → en route → at pickup → in progress. The driver
 * drives it; this only refuses the steps that are not possible from where the run is.
 *
 * 🔴 Two statuses, kept in step deliberately. `trip_sessions.current_status` is where
 * the run is right now, and `scheduled_trips.status` is what the rest of the product
 * reads — search, the home screens, the booking rules. They could have been one column,
 * and they are not, because the trip row exists before the run does and outlives it:
 * a day that was never started has no session at all. Writing both here, in one place,
 * is what stops them drifting.
 */
final readonly class AdvanceTripAction
{
    /**
     * The trip status each session status implies.
     *
     * `at_pickup` maps to `en_route` on purpose: standing at a gate waiting for somebody
     * is part of being on the way, and `scheduled_trips` has no state for it. Inventing
     * one would mean a status the booking rules and the search engine would both have to
     * learn about, to describe a minute of a driver's morning.
     */
    private const array TRIP_STATUS = [
        TripSessionStatus::Preparing->value => ScheduledTripStatus::Preparing,
        TripSessionStatus::EnRoute->value => ScheduledTripStatus::EnRoute,
        TripSessionStatus::AtPickup->value => ScheduledTripStatus::EnRoute,
        TripSessionStatus::InProgress->value => ScheduledTripStatus::InProgress,
        TripSessionStatus::Completed->value => ScheduledTripStatus::Completed,
        TripSessionStatus::Cancelled->value => ScheduledTripStatus::Cancelled,
        // No mapping for `emergency`: an interrupted run has not finished and has not
        // been cancelled, and saying either would be a claim about what happened that
        // only a human reviewing it can make (Phase 11).
    ];

    public function execute(TripSession $session, TripSessionStatus $next): TripSession
    {
        $this->assertCanTransitionTo($session, $next);

        return DB::transaction(function () use ($session, $next): TripSession {
            $changes = ['current_status' => $next->value];

            /*
             * The moment the car moved, which is what the on-time rate is measured from.
             * Recorded on the FIRST entry into `in_progress` only: a run that stops to
             * collect somebody else and sets off again has one departure, not two.
             */
            if ($next === TripSessionStatus::InProgress && $session->departed_at === null) {
                $changes['departed_at'] = now();
            }

            $session->forceFill($changes)->save();

            $tripStatus = self::TRIP_STATUS[$next->value] ?? null;

            if ($tripStatus !== null) {
                $session->scheduledTrip->forceFill(['status' => $tripStatus->value])->save();
            }

            return $session;
        });
    }

    /**
     * 🔴 The refusal names where the run IS and what it could do instead, rather than
     * only that the step failed. A driver who taps "arrived" on a run she has not
     * started yet needs to be told to start it — "invalid transition" tells her the app
     * is broken.
     */
    public static function assertCanTransitionTo(TripSession $session, TripSessionStatus $next): void
    {
        if ($session->current_status->canTransitionTo($next)) {
            return;
        }

        $allowed = [];

        foreach (TripSessionStatus::cases() as $case) {
            if ($session->current_status->canTransitionTo($case)) {
                $allowed[] = strtoupper($case->value);
            }
        }

        throw DomainException::of(ErrorCode::TripInvalidTransition, fields: [
            'currentStatus' => [strtoupper($session->current_status->value)],
            'allowed' => $allowed,
        ]);
    }

    /**
     * The run the caller is asking about, or a refusal that it has not begun.
     *
     * Every endpoint after "start" needs this, and each of them phrasing it themselves
     * is how one of them ends up doing it differently.
     */
    public static function sessionFor(ScheduledTrip $trip): TripSession
    {
        return $trip->tripSession
            ?? throw DomainException::of(ErrorCode::TripNotStarted);
    }
}
