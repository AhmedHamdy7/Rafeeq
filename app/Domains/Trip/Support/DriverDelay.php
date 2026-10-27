<?php

namespace App\Domains\Trip\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;
use Carbon\CarbonImmutable;

/**
 * Has the driver let this passenger down badly enough that leaving should cost her nothing?
 * (Feature 03 v5 §241.)
 *
 * > *"Passenger can exit without fee if driver hasn't started by departure or missed agreed
 * > pickup."*
 *
 * 🔴 **The server answers this, never the client.** The whole value of the entitlement is that it
 * cannot be claimed — a flag in a request body saying "my driver was late" would be a way to cancel
 * for free whenever you felt like it, and the person it would cost is the driver, who was on time.
 *
 * 🔴 **And both conditions are FACTS, not estimates.** That constraint shaped the second one. v5
 * says "missed agreed pickup", but nothing in this system stores an agreed pickup *time* — a
 * booking carries an agreed pickup *place*, and the only time attached to it is the routing
 * provider's ETA, which is rebuilt whenever the stops change. Treating that estimate as a
 * commitment would mean telling a driver she broke a promise she never made. So the question asked
 * here is the one the data can answer honestly: **has the car driven past her?** A stop later in the
 * route has been reached and hers has not — which is not a prediction about when she would be
 * collected, it is a record that she was skipped.
 */
final class DriverDelay
{
    /**
     * Is she entitled to leave with no fee?
     */
    public static function entitlesFreeExit(Booking $booking, ScheduledTrip $trip): bool
    {
        return self::neverSetOff($trip) || self::drovePastHer($booking);
    }

    /**
     * Departure has passed and the run has not begun.
     *
     * ⚠️ **No grace period, deliberately.** v5 says "hasn't started by departure", and a tolerance
     * here would be the platform deciding how long a woman should stand in the street before her
     * own time is her own again. Nothing happens automatically at this moment — it only becomes
     * HER option. She is free to wait, and most people will.
     *
     * Note what this is not: the driver's on-time record is judged against
     * `trip.on_time_threshold_minutes` (ten minutes), and that is a different question with a
     * different purpose. One decides whether she is marked late; this decides whether somebody else
     * is trapped.
     */
    public static function neverSetOff(ScheduledTrip $trip): bool
    {
        if ($trip->departure_at === null) {
            return false;
        }

        if (CarbonImmutable::now()->lessThan($trip->departure_at)) {
            return false;
        }

        $session = $trip->tripSession;

        // Started counts even if it started late: once the car is moving, the question becomes
        // whether it reaches her, which is `drovePastHer()` below.
        return $session === null || $session->started_at === null;
    }

    /**
     * The car has gone past her stop without collecting her.
     *
     * Decided on `reached_at` alone: a stop further along her route is marked reached and hers is
     * not. No ETA, no clock, no tolerance — the driver's own progress through her own itinerary
     * says it.
     */
    public static function drovePastHer(Booking $booking): bool
    {
        $stops = NavigationStop::query()
            ->where('scheduled_trip_id', $booking->scheduled_trip_id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['id', 'booking_id', 'kind', 'sequence', 'reached_at']);

        $hers = $stops->first(fn (NavigationStop $stop): bool => $stop->booking_id === $booking->getKey()
            && $stop->kind === NavigationStopKind::PassengerPickup);

        if ($hers === null || $hers->reached_at !== null) {
            return false;
        }

        // Anything after her pickup that the car has already reached.
        return $stops
            ->contains(fn (NavigationStop $stop): bool => $stop->sequence > $hers->sequence
                && $stop->reached_at !== null);
    }

    /**
     * Why she is entitled, for the client's card. Null when she is not.
     *
     * Returned as a reason rather than a bare boolean because the two read completely differently
     * on a screen: one is "your driver has not set off", the other is "your driver has passed your
     * stop" — and the second is the one that means stop waiting.
     */
    public static function reasonFor(Booking $booking, ScheduledTrip $trip): ?string
    {
        return match (true) {
            self::neverSetOff($trip) => 'not_started',
            self::drovePastHer($booking) => 'passed_your_stop',
            default => null,
        };
    }
}
