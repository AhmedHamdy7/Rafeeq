<?php

namespace App\Domains\Commute\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Support\StopOrder;
use Carbon\CarbonImmutable;

/**
 * When the driver has to leave to arrive on time (C-2, COM-15, decision C9).
 *
 * The driver states the arrival; this answers the departure, from the route **as it stands that
 * day** — his start, every confirmed rider's agreed pickup and drop-off in road order, his end —
 * timed by the geo engine for a car that must arrive by that moment. With the Google engine that
 * is the morning's traffic; with the straight-line engine it is an assumed average speed.
 *
 * 🔴 Rounded DOWN to the minute: "leave at 07:12:40" is a time nobody can act on, and rounding up
 * would make the promise late by up to a minute every morning.
 *
 * 🔒 The cap on how early this can get is not here: it is the whole-trip detour cap (COM-31) that
 * every rider's points were checked against before she was confirmed. This only reports what the
 * accepted route costs.
 */
final readonly class ComputedDeparture
{
    public function __construct(private GeoQueryEngine $geo) {}

    /**
     * The departure for a day with a promised arrival, or null when the day has none.
     */
    public function forTrip(ScheduledTrip $trip): ?CarbonImmutable
    {
        if ($trip->promised_arrival_at === null) {
            return null;
        }

        $offer = $trip->commuteOffer()->with('locations')->first();

        if ($offer === null) {
            return null;
        }

        return $this->arrivingBy($offer, CarbonImmutable::parse($trip->promised_arrival_at), $this->riderPoints($trip));
    }

    /**
     * The departure for an arrival, through the given rider points (none for a day nobody has
     * booked yet).
     *
     * @param  list<array{bookingId: string|null, kind: NavigationStopKind, point: Coordinate, label: string|null, reachedAt: CarbonImmutable|null}>  $riderPoints
     */
    public function arrivingBy(CommuteOffer $offer, CarbonImmutable $arriveBy, array $riderPoints = []): ?CarbonImmutable
    {
        $origin = $this->pointOf($offer, CommuteLocationType::Origin);
        $destination = $this->pointOf($offer, CommuteLocationType::Destination);

        if ($origin === null || $destination === null) {
            return null;
        }

        $via = array_map(
            fn (array $stop): Coordinate => $stop['point'],
            StopOrder::along($this->geo, $origin, $destination, $riderPoints),
        );

        $route = $this->geo->routeArrivingBy($origin, $destination, $via, $arriveBy);

        return $arriveBy->subSeconds($route->durationSeconds)->startOfMinute();
    }

    /**
     * Two points per confirmed booking, as agreed on the booking itself.
     *
     * @return list<array{bookingId: string|null, kind: NavigationStopKind, point: Coordinate, label: string|null, reachedAt: CarbonImmutable|null}>
     */
    private function riderPoints(ScheduledTrip $trip): array
    {
        $points = [];

        $bookings = Booking::query()
            ->where('scheduled_trip_id', $trip->getKey())
            ->where('status', BookingStatus::Confirmed->value)
            ->whereNotNull('pickup_lat')
            ->whereNotNull('dropoff_lat')
            ->orderBy('id')
            ->get();

        foreach ($bookings as $booking) {
            $points[] = [
                'bookingId' => (string) $booking->getKey(),
                'kind' => NavigationStopKind::PassengerPickup,
                'point' => new Coordinate((float) $booking->pickup_lat, (float) $booking->pickup_lng),
                'label' => null,
                'reachedAt' => null,
            ];
            $points[] = [
                'bookingId' => (string) $booking->getKey(),
                'kind' => NavigationStopKind::PassengerDropoff,
                'point' => new Coordinate((float) $booking->dropoff_lat, (float) $booking->dropoff_lng),
                'label' => null,
                'reachedAt' => null,
            ];
        }

        return $points;
    }

    private function pointOf(CommuteOffer $offer, CommuteLocationType $type): ?Coordinate
    {
        $location = $offer->locations->firstWhere('type', $type);

        return $location === null ? null : new Coordinate((float) $location->lat, (float) $location->lng);
    }
}
