<?php

namespace App\Domains\Trip\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Trip\Models\TripWaitTimer;
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
 * D-0020 (2026-10-28) sets the timing: five minutes after departure if the run has not begun, or
 * five minutes after her agreed pickup time ({@see PickupTime}) if it has begun but not reached her.
 * A car that has driven past her stop entitles her at once.
 */
final class DriverDelay
{
    /**
     * Is she entitled to leave with no fee?
     */
    public static function entitlesFreeExit(Booking $booking, ScheduledTrip $trip): bool
    {
        return self::reasonFor($booking, $trip) !== null;
    }

    /**
     * The run has not begun five minutes after departure (TRIP-05, D-0020).
     */
    public static function neverSetOff(ScheduledTrip $trip): bool
    {
        if ($trip->departure_at === null) {
            return false;
        }

        $opensAt = CarbonImmutable::instance($trip->departure_at)
            ->addMinutes((int) config('rafeeq.trip.delay_passenger_warning_minutes'));

        if (CarbonImmutable::now()->lessThan($opensAt)) {
            return false;
        }

        $session = $trip->tripSession;

        return $session === null || $session->started_at === null;
    }

    public static function lateForPickup(Booking $booking, ScheduledTrip $trip): bool
    {
        $lateness = (int) config('rafeeq.trip.driver_late_for_pickup_minutes');

        if ($trip->departure_at === null
            || CarbonImmutable::now()->lessThan(CarbonImmutable::instance($trip->departure_at)->addMinutes($lateness))) {
            return false;
        }

        $session = $trip->tripSession;

        if ($session === null || $session->started_at === null || $session->current_status->isTerminal()) {
            return false;
        }

        $pickupAt = PickupTime::for((clone $booking)->setRelation('scheduledTrip', $trip));

        if ($pickupAt === null) {
            return false;
        }

        $opensAt = $pickupAt->addMinutes($lateness);

        if (CarbonImmutable::now()->lessThan($opensAt)) {
            return false;
        }

        $arrived = TripWaitTimer::query()
            ->where('trip_session_id', $session->getKey())
            ->where('booking_id', $booking->getKey())
            ->whereNotNull('arrived_at')
            ->exists();

        if ($arrived) {
            return false;
        }

        return ! NavigationStop::query()
            ->where('scheduled_trip_id', $booking->scheduled_trip_id)
            ->where('booking_id', $booking->getKey())
            ->where('kind', NavigationStopKind::PassengerPickup)
            ->whereNotNull('reached_at')
            ->exists();
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
        if (Attendance::query()->whereKey($booking->getKey())->whereNot('status', AttendanceStatus::Pending->value)->exists()) {
            return null;
        }

        return match (true) {
            self::neverSetOff($trip) => 'not_started',
            self::drovePastHer($booking) => 'passed_your_stop',
            self::lateForPickup($booking, $trip) => 'late_for_pickup',
            default => null,
        };
    }
}
