<?php

namespace App\Domains\Trip\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Actions\ArriveAtPickupAction;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The time a passenger was told to be ready at her pickup on one day.
 *
 * The run's departure plus the driving time to her pickup along this run's itinerary (the legs up
 * to and including hers), or the departure itself when no itinerary was built.
 *
 * 🔴 Counted from `departure_at` rather than read from the stored `eta_at`, because the departure
 * moves (C-2: recomputed as riders join or leave, until the 12 h lock) and an ETA written before the
 * move would name a time nobody told her any more.
 *
 * One place, because two things read it and they must agree: the grace clock an arrival starts
 * ({@see ArriveAtPickupAction}), and the "07:05" on her Home card. A card
 * that said 07:05 while the grace counted from 07:08 would be a fee charged against a time she was
 * never shown.
 */
final class PickupTime
{
    public static function for(Booking $booking): ?CarbonImmutable
    {
        $stops = NavigationStop::query()
            ->where('scheduled_trip_id', $booking->scheduled_trip_id)
            ->orderBy('sequence')
            ->get(['booking_id', 'kind', 'sequence', 'leg_duration_seconds']);

        return self::fromStops($booking, $stops);
    }

    /**
     * The same answer from stops already loaded, ordered by `sequence`.
     *
     * @param  Collection<int, NavigationStop>  $stops
     */
    public static function fromStops(Booking $booking, Collection $stops): ?CarbonImmutable
    {
        $departure = $booking->scheduledTrip->departure_at;

        if ($departure === null) {
            return null;
        }

        $offset = 0;

        foreach ($stops as $stop) {
            $offset += (int) $stop->leg_duration_seconds;

            if ($stop->booking_id === $booking->id && $stop->kind === NavigationStopKind::PassengerPickup) {
                return CarbonImmutable::instance($departure)->addSeconds($offset);
            }
        }

        return CarbonImmutable::instance($departure);
    }
}
