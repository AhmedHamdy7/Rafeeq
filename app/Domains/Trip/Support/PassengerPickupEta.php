<?php

namespace App\Domains\Trip\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;
use Illuminate\Support\Collection;

final class PassengerPickupEta
{
    public const array NONE = ['pickupEtaAt' => null, 'carToPickupPolyline' => null, 'stopsBeforeYou' => null];

    /**
     * @param  Collection<int, NavigationStop>  $stops
     * @return array{pickupEtaAt: string|null, carToPickupPolyline: string|null, stopsBeforeYou: int|null}
     */
    public static function view(Booking $booking, Collection $stops, LiveStopEtas $live): array
    {
        $hers = $stops->first(fn (NavigationStop $stop): bool => $stop->booking_id === $booking->id
            && $stop->kind === NavigationStopKind::PassengerPickup);

        if ($hers === null || $hers->reached_at !== null || ! $live->isLive) {
            return self::NONE;
        }

        if ($live->arrivedAtFor($hers) !== null) {
            return [...self::NONE, 'stopsBeforeYou' => 0];
        }

        return [
            'pickupEtaAt' => $live->etaFor($hers)?->toIso8601String(),
            'carToPickupPolyline' => $live->nextStopId === (string) $hers->getKey() ? $live->carToNextStopPolyline : null,
            'stopsBeforeYou' => LiveStopEtas::stopsBefore($stops, $hers),
        ];
    }
}
