<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\ScheduleLock;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Support\Polyline;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use App\Domains\Trip\Actions\ArriveAtPickupAction;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * When the car should reach a passenger's point — "Pickup around 7:55" (DISCOVER-14, handoff B-3).
 *
 * Two answers, and which one depends on how much is known:
 *
 * - **Once the day's itinerary is built** — the departure plus the measured legs up to her stop.
 *   This is the figure the wait timer starts her grace from ({@see ArriveAtPickupAction}),
 *   so the time she is shown and the time she is held to are one number. The itinerary is rebuilt
 *   only while stops can still change, and they stop changing at the 16-hour lock
 *   ({@see ScheduleLock}), so from then on it is frozen.
 * - **Before that, or before any booking exists** — the departure plus the share of the
 *   published route's duration that lies before her point. Arithmetic on geometry already stored:
 *   no provider call, so the request screen can ask it every time the pin moves.
 *
 * 🔴 An estimate either way, and named as one in the API (`estimatedPickupAt`, `pickupEta`). The
 * detour her own stop adds is not in it, and neither is traffic. The driver's promise is the
 * arrival, not this.
 */
final class PickupEta
{
    /**
     * The departure plus how far along the published route this point sits.
     *
     * Null when the commute has no readable route — a draft, or broken geometry. Nothing is better
     * than a time computed from nothing.
     */
    public static function alongRoute(GeoQueryEngine $geo, CommuteOffer $offer, ScheduledTrip $trip, Coordinate $point): ?CarbonImmutable
    {
        $seconds = self::secondsAlong($geo, $offer, $point);

        return $seconds === null ? null : CarbonImmutable::instance($trip->departure_at)->addSeconds($seconds);
    }

    /**
     * Seconds from the start of the run to this point, at the published route's own pace.
     */
    public static function secondsAlong(GeoQueryEngine $geo, CommuteOffer $offer, Coordinate $point): ?int
    {
        if ($offer->route_polyline === null || ! Polyline::isReadable($offer->route_polyline)) {
            return null;
        }

        $route = new Route(
            polyline: $offer->route_polyline,
            distance: Distance::fromMetres((int) $offer->route_distance_meters),
            durationSeconds: (int) $offer->route_duration_seconds,
        );

        $progress = max(0.0, min(1.0, $geo->progressAlongRoute($route, $point)));

        return (int) round($progress * (int) $offer->route_duration_seconds);
    }

    /**
     * The same instant on the commute's own wall clock, for display — `departureLocal` moved by the
     * same number of seconds, so no timezone arithmetic happens on a phone.
     */
    public static function local(ScheduledTrip $trip, ?CarbonImmutable $at): ?string
    {
        if ($at === null || $trip->departure_local === null) {
            return null;
        }

        $offset = CarbonImmutable::instance($trip->departure_at)->diffInSeconds($at, false);

        return CarbonImmutable::instance($trip->departure_local)->addSeconds((int) $offset)->format('Y-m-d H:i:s');
    }

    /**
     * From the day's built itinerary: the departure plus every leg up to her pickup.
     *
     * Null when no itinerary has been built yet, or it has no pickup for this booking — the caller
     * falls back to {@see alongRoute()}.
     *
     * @param  Collection<int, NavigationStop>|null  $stops  the trip's stops in order, when the
     *                                                       caller already loaded them for a page
     */
    public static function fromItinerary(Booking $booking, ?Collection $stops = null): ?CarbonImmutable
    {
        $departure = $booking->scheduledTrip?->departure_at;

        if ($departure === null) {
            return null;
        }

        $stops ??= NavigationStop::query()
            ->where('scheduled_trip_id', $booking->scheduled_trip_id)
            ->orderBy('sequence')
            ->get(['booking_id', 'kind', 'sequence', 'leg_duration_seconds']);

        $offset = 0;

        foreach ($stops as $stop) {
            $offset += (int) $stop->leg_duration_seconds;

            if ($stop->booking_id === $booking->id && $stop->kind === NavigationStopKind::PassengerPickup) {
                return CarbonImmutable::instance($departure)->addSeconds($offset);
            }
        }

        return null;
    }

    /**
     * The best answer for a booking: the itinerary when there is one, the route otherwise.
     *
     * @param  Collection<int, NavigationStop>|null  $stops
     */
    public static function forBooking(GeoQueryEngine $geo, Booking $booking, ?Collection $stops = null): ?CarbonImmutable
    {
        $fromItinerary = self::fromItinerary($booking, $stops);

        if ($fromItinerary !== null) {
            return $fromItinerary;
        }

        $trip = $booking->scheduledTrip;
        $offer = $trip?->commuteOffer;

        if ($trip === null || $offer === null || $booking->pickup_lat === null) {
            return null;
        }

        return self::alongRoute($geo, $offer, $trip, new Coordinate((float) $booking->pickup_lat, (float) $booking->pickup_lng));
    }
}
