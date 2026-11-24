<?php

namespace App\Http\Resources;

use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;

/**
 * `linkedCommute` on a driver's day row (round 2, item 2 of 2026-11-24): the other commute of the
 * pair in any live state, drafts included, and its day on the same date. The day fields are null
 * when it doesn't run that date; the whole object is null when the commute isn't linked.
 */
final class LinkedDay
{
    /**
     * @return array{id: string, direction: string, status: string, tripId: string|null, departureLocal: string|null, promisedArrivalLocal: string|null, estimatedArrivalLocal: string|null}|null
     */
    public static function for(ScheduledTrip $trip): ?array
    {
        $linked = self::linkedOffer((string) $trip->commute_offer_id);

        if ($linked === null) {
            return null;
        }

        $day = ScheduledTrip::query()
            ->where('commute_offer_id', $linked->getKey())
            ->where('trip_date', $trip->trip_date->toDateString())
            ->whereNotIn('status', [ScheduledTripStatus::Cancelled->value, ScheduledTripStatus::Expired->value])
            ->orderBy('departure_at')
            ->first();

        return [
            'id' => (string) $linked->getKey(),
            'direction' => $linked->direction->value,
            'status' => strtoupper($linked->status->value),
            'tripId' => $day === null ? null : (string) $day->getKey(),
            'departureLocal' => $day?->departure_local?->format('Y-m-d H:i:s'),
            'promisedArrivalLocal' => $day === null ? null : TripTimes::local($day, $day->promised_arrival_at),
            'estimatedArrivalLocal' => $day === null ? null : TripTimes::local($day, TripTimes::estimatedArrival($day)),
        ];
    }

    public static function linkedOffer(string $offerId): ?CommuteOffer
    {
        $offer = CommuteOffer::query()->whereKey($offerId)->first(['id', 'direction', 'return_of_commute_id']);

        if ($offer === null) {
            return null;
        }

        if ($offer->isReturn()) {
            if ($offer->return_of_commute_id === null) {
                return null;
            }

            return CommuteOffer::query()
                ->whereKey($offer->return_of_commute_id)
                ->where('status', '!=', CommuteOfferStatus::Archived->value)
                ->first(['id', 'direction', 'status']);
        }

        return CommuteOffer::query()
            ->where('return_of_commute_id', $offerId)
            ->whereIn('status', [CommuteOfferStatus::Draft->value, CommuteOfferStatus::Published->value, CommuteOfferStatus::Paused->value])
            ->latest()
            ->first(['id', 'direction', 'status']);
    }
}
