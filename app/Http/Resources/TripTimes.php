<?php

namespace App\Http\Resources;

use App\Domains\Commute\Enums\CommuteDirection;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use DateTimeInterface;

/**
 * The direction of a day and its Return times (B1, B2 of 2026-11-23), the same everywhere a day is
 * drawn.
 *
 * A Going day is timed by its promised arrival; a Return day by its promised leave time, with an
 * estimated arrival. Local times are on the day's own wall clock, in the shape of `departureLocal`.
 */
final class TripTimes
{
    public static function direction(ScheduledTrip $trip): string
    {
        if ($trip->relationLoaded('commuteOffer') && $trip->commuteOffer instanceof CommuteOffer) {
            return $trip->commuteOffer->direction->value;
        }

        return $trip->promised_departure_at !== null ? CommuteDirection::Return->value : CommuteDirection::Going->value;
    }

    /**
     * @return array{direction: string, promisedDepartureAt: string|null, promisedDepartureLocal: string|null, estimatedArrivalAt: string|null, estimatedArrivalLocal: string|null}
     */
    public static function fields(ScheduledTrip $trip): array
    {
        $estimated = $trip->estimated_arrival_at ?? $trip->promised_arrival_at;

        return [
            'direction' => self::direction($trip),
            'promisedDepartureAt' => $trip->promised_departure_at?->toIso8601String(),
            'promisedDepartureLocal' => self::local($trip, $trip->promised_departure_at),
            'estimatedArrivalAt' => $estimated?->toIso8601String(),
            'estimatedArrivalLocal' => self::local($trip, $estimated),
        ];
    }

    /**
     * @return array{direction: null, promisedDepartureAt: null, promisedDepartureLocal: null, estimatedArrivalAt: null, estimatedArrivalLocal: null}
     */
    public static function empty(): array
    {
        return [
            'direction' => null,
            'promisedDepartureAt' => null,
            'promisedDepartureLocal' => null,
            'estimatedArrivalAt' => null,
            'estimatedArrivalLocal' => null,
        ];
    }

    /**
     * @return array{direction: string|null, promisedDepartureAt: string|null, promisedDepartureLocal: string|null, estimatedArrivalAt: string|null, estimatedArrivalLocal: string|null}
     */
    public static function orEmpty(?ScheduledTrip $trip): array
    {
        if ($trip === null) {
            return self::empty();
        }

        return self::fields($trip);
    }

    public static function estimatedArrival(ScheduledTrip $trip): ?DateTimeInterface
    {
        return $trip->estimated_arrival_at ?? $trip->promised_arrival_at;
    }

    public static function local(ScheduledTrip $trip, ?DateTimeInterface $at): ?string
    {
        if ($at === null || $trip->departure_local === null) {
            return null;
        }

        $offset = $trip->departure_at->diffInSeconds($at, false);

        return $trip->departure_local->copy()->addSeconds((int) $offset)->format('Y-m-d H:i:s');
    }

    public static function clock(?string $local): ?string
    {
        return $local === null ? null : substr($local, 11, 5);
    }
}
