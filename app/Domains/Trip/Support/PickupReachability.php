<?php

namespace App\Domains\Trip\Support;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Actions\RecordTripLocationAction;
use App\Domains\Trip\Models\TripSession;

/**
 * Can this pickup still be collected by a car that is already on the road?
 * (Feature 03 v5 §148 and seat-allocation point 8.)
 *
 * > *"open while seat remains free and passenger pickup is **reachable**, including after commute
 * > starts; close on seat taken, trip expiry/finish, or pickup becoming unreachable."*
 *
 * > *"**No fixed offer expiry.** On active trip, reachability depends on **actual route progress and
 * > predicted ETA**. Trip start alone doesn't invalidate offers."*
 *
 * 🔴 **This is the piece v5 names and does not define, so what it is built from matters more than
 * usual.** Two questions, and both are answered from measured values rather than guesses:
 *
 * 1. **Has the car already gone past the point?** Answered by projecting the car's last known
 *    position and the pickup onto the driver's own route and comparing how far along each falls.
 *    `progressAlongRoute` exists for exactly this — it was extracted when the navigation itinerary
 *    needed to order stops by road position rather than by naive pairing.
 * 2. **Would collecting her break what the people already aboard were promised?** Answered by
 *    measuring the detour against the slack left before the promised arrival. No promise means
 *    nothing to break, and then the driver's own `max_detour_minutes` is the limit — the number she
 *    chose to say how far out of her way she will go.
 *
 * ⚠️ **What it deliberately does NOT do is invent a time limit.** v5 says "no fixed offer expiry"
 * twice, and the temptation here is a rule like "unreachable thirty minutes after departure"
 * because it is easy and needs no geometry. That would close an offer on a run that was crawling
 * five minutes from her gate, and keep one open on a run that had passed her street.
 *
 * 🔴 And it answers NO when it cannot tell. No route, no known position, no stops — the honest
 * reading of silence is that we do not know where the car is, and offering somebody a seat on that
 * basis is offering her a lift that may never come.
 */
final readonly class PickupReachability
{
    public function __construct(
        private GeoQueryEngine $geo,
        private RecordTripLocationAction $locations,
    ) {}

    /**
     * Is this point still collectable on this run?
     */
    public function isReachable(ScheduledTrip $trip, Coordinate $pickup): bool
    {
        return $this->refusalFor($trip, $pickup) === null;
    }

    /**
     * The same question as a refusal, for the paths that must stop rather than branch.
     *
     * The reason travels in `error.fields.reachability` so a client can say which — a car that has
     * passed her street is a no she can do nothing about, while a detour that would make the others
     * late is a no about this morning's load.
     */
    public function assertReachable(ScheduledTrip $trip, Coordinate $pickup): void
    {
        $refusal = $this->refusalFor($trip, $pickup);

        if ($refusal !== null) {
            throw DomainException::of(ErrorCode::PickupUnreachable, fields: [
                'reachability' => [$refusal],
            ]);
        }
    }

    /**
     * Why not, for a client that wants to say which — or null when it is reachable.
     *
     * The reasons are kept apart because they mean different things to the person asking: a car
     * that has passed her street is a no she can do nothing about, while a detour that would make
     * the others late is a no about this morning's load.
     */
    public function refusalFor(ScheduledTrip $trip, Coordinate $pickup): ?string
    {
        $session = $trip->tripSession ?? TripSession::query()
            ->where('scheduled_trip_id', $trip->getKey())
            ->first();

        if ($session === null || ! $session->current_status->isUnderway()) {
            // Not a reachability question at all: the run has not set off, so the ordinary booking
            // rules apply and this class has nothing to say.
            return 'not_underway';
        }

        $route = $this->routeOf($trip);

        if ($route === null) {
            return 'route_unknown';
        }

        $position = $this->lastKnownPosition($trip);

        if ($position === null) {
            /*
             * 🔴 No. A run with no position report is a run we cannot place, and the whole
             * entitlement rests on knowing where the car is.
             */
            return 'position_unknown';
        }

        if ($this->geo->progressAlongRoute($route, $position) >= $this->geo->progressAlongRoute($route, $pickup)) {
            return 'already_passed';
        }

        $detourMinutes = $this->geo->detourMinutes($route, $pickup);

        $slack = $this->slackMinutes($trip);

        if ($slack !== null) {
            return $detourMinutes <= $slack ? null : 'would_break_promise';
        }

        // No promised arrival to protect, so the driver's own tolerance is the limit.
        return $detourMinutes <= (int) $trip->commuteOffer?->max_detour_minutes
            ? null
            : 'detour_too_long';
    }

    /**
     * How many minutes are left before the arrival the people aboard were promised.
     *
     * Null when the day carries no promise — nothing is scored against null, which is the same rule
     * the on-time metric and the sixteen-hour lock both follow.
     */
    private function slackMinutes(ScheduledTrip $trip): ?int
    {
        if ($trip->promised_arrival_at === null) {
            return null;
        }

        $remaining = now()->diffInMinutes($trip->promised_arrival_at, absolute: false);

        /*
         * Already late: the slack is gone and nothing may be added. Returning zero rather than a
         * negative keeps the comparison above readable — a detour of zero minutes does not exist,
         * so this is a no either way.
         */
        return (int) max(0, $remaining);
    }

    /**
     * The driver's advertised route, which is what both questions are measured along.
     */
    private function routeOf(ScheduledTrip $trip): ?Route
    {
        $trip->loadMissing('commuteOffer.locations');

        $origin = $trip->commuteOffer?->locations
            ->firstWhere('type', CommuteLocationType::Origin);

        $destination = $trip->commuteOffer?->locations
            ->firstWhere('type', CommuteLocationType::Destination);

        if ($origin === null || $destination === null) {
            return null;
        }

        return $this->geo->routeBetween(
            new Coordinate((float) $origin->lat, (float) $origin->lng),
            new Coordinate((float) $destination->lat, (float) $destination->lng),
        );
    }

    /**
     * Where the car last said it was.
     *
     * 🔒 Read here and never published. This class answers a yes/no; the position itself goes
     * nowhere, because a passenger learning where a driver is at an arbitrary moment is the thing
     * the live-location rules exist to prevent.
     */
    private function lastKnownPosition(ScheduledTrip $trip): ?Coordinate
    {
        $session = $trip->tripSession ?? TripSession::query()
            ->where('scheduled_trip_id', $trip->getKey())
            ->first();

        if ($session === null) {
            return null;
        }

        /*
         * 🔴 Asked of the SAME source the live map reads, and not of `trip_locations`.
         *
         * Positions are buffered and written to the table by a sweep every thirty seconds, so the
         * table is up to half a minute behind — and this decision is about where the car is now. A
         * query against the table looked right and answered 'position unknown' for the first half
         * minute of every run, which is exactly when somebody would be asking.
         */
        $position = $this->locations->current($session);

        return $position === null
            ? null
            : new Coordinate((float) $position->lat, (float) $position->lng);
    }
}
