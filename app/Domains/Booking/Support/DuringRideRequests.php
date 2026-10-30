<?php

namespace App\Domains\Booking\Support;

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\Support\PickupReachability;

/**
 * Whether a run that has already set off is still taking requests (Feature 03 v5 §148, §416).
 *
 * > *"Driver may accept new active-ride requests if feasible; those separate incoming active-ride
 * > requests expire in 3 minutes."* And: *"toggle `Receive Requests During Ride` ON by default."*
 *
 * 🔴 **This decides which set of rules a request is judged by**, which is why it is a question of
 * its own rather than a flag threaded through the booking checks. An ordinary request is refused
 * after its departure and after the night-before deadline, both of which are meaningless here — the
 * run has departed, that is the premise. What replaces them is: the driver said she is willing to be
 * asked, the car is actually moving, and there is a seat.
 *
 * ⚠️ Reachability is NOT asked here. It needs the passenger's resolved pickup and may call a routing
 * provider, so it is asked once the points exist — see
 * {@see PickupReachability}. Splitting the cheap half from the expensive
 * half means a request refused for having no seat never costs a provider call.
 */
final class DuringRideRequests
{
    /**
     * Is this a request to join a run that is already under way?
     *
     * Asked of the day rather than of the payload: a client does not declare that it is making one
     * of these, because the circumstances decide it and a client that could declare it would be
     * choosing which rules to be judged by.
     */
    public static function applies(ScheduledTrip $trip): bool
    {
        $session = $trip->tripSession ?? TripSession::query()
            ->where('scheduled_trip_id', $trip->getKey())
            ->first();

        return $session !== null && $session->current_status->isUnderway();
    }

    /**
     * The cheap conditions, all three of which are about this morning rather than about her.
     */
    public static function assertStillTakingRequests(ScheduledTrip $trip): void
    {
        /*
         * 🔴 Her switch, and the reason it existed before anything read it. `Receive Requests
         * During Ride` has been collected per morning since 2026-10-24, defaulting to yes, so every
         * driver's stored answer already describes what she wants — and a driver who turned it off
         * back then is honoured the first time it means anything, which was the whole argument for
         * collecting it early.
         */
        if (! $trip->receives_requests_during_ride) {
            throw DomainException::of(ErrorCode::SeatUnavailable, fields: [
                'duringRide' => ['NOT_ACCEPTING'],
            ]);
        }

        if ($trip->seats_taken >= $trip->seats_total) {
            throw DomainException::of(ErrorCode::SeatUnavailable, fields: [
                'seatsAvailable' => ['0'],
            ]);
        }
    }

    /**
     * How long one of these waits for an answer.
     */
    public static function expiryMinutes(): int
    {
        return (int) config('rafeeq.booking.during_ride_request_expiry_minutes');
    }
}
