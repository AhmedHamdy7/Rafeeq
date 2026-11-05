<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\RouteSettings;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Http\Resources\LocalisedLabel;

final readonly class CounterofferOptions
{
    public function __construct(private GeoQueryEngine $geo) {}

    public function for(SeatRequest $request, ?ScheduledTrip $day): array
    {
        if ($request->status !== SeatRequestStatus::Pending) {
            throw DomainException::of(ErrorCode::SeatRequestNotPending);
        }

        $offer = $request->commuteOffer ?? throw DomainException::of(ErrorCode::NotFound);
        $offer->loadMissing('locations');

        $pickup = new Coordinate((float) $request->requested_pickup_lat, (float) $request->requested_pickup_lng);
        $dropoff = new Coordinate((float) $request->requested_dropoff_lat, (float) $request->requested_dropoff_lng);
        $requested = PickupDetour::measureAll($this->geo, $offer, [$pickup, $dropoff]);

        return [
            'tripDate' => $day?->trip_date->toDateString(),
            'departureLocal' => $day?->departure_local->format('Y-m-d H:i:s'),
            'maxDetourMinutes' => RouteSettings::maxTripDetourMinutes(),
            'canDropPin' => true,
            'requested' => [
                'pickup' => ['label' => $request->requested_pickup_label, 'lat' => $pickup->lat, 'lng' => $pickup->lng],
                'dropoff' => ['label' => $request->requested_dropoff_label, 'lat' => $dropoff->lat, 'lng' => $dropoff->lng],
                'addedMinutes' => (int) round($requested->addedMinutes),
                'withinLimit' => $requested->isWithin(),
                'tier' => $requested->tier()?->value,
                'extraMinutes' => $requested->extraMinutes(),
            ],
            'route' => [
                'polyline' => $offer->route_polyline,
                'distanceMeters' => (int) $offer->route_distance_meters,
                'durationSeconds' => (int) $offer->route_duration_seconds,
            ],
            'pickups' => $this->options(
                $offer,
                CommuteLocationType::Pickup,
                $request->requested_pickup_suggestion_id,
                $pickup,
                fn (Coordinate $candidate): array => [$candidate, $dropoff],
                $requested->addedMinutes,
            ),
            'dropoffs' => $this->options(
                $offer,
                CommuteLocationType::Dropoff,
                $request->requested_dropoff_suggestion_id,
                $dropoff,
                fn (Coordinate $candidate): array => [$pickup, $candidate],
                $requested->addedMinutes,
            ),
        ];
    }

    private function options(
        CommuteOffer $offer,
        CommuteLocationType $type,
        ?string $chosenSuggestionId,
        Coordinate $chosen,
        callable $pair,
        float $requestedMinutes,
    ): array {
        return $offer->locations
            ->where('type', $type)
            ->reject(fn (CommuteLocation $stop): bool => (string) $stop->getKey() === $chosenSuggestionId)
            ->sortBy('sequence')
            ->values()
            ->map(function (CommuteLocation $stop) use ($offer, $chosen, $pair, $requestedMinutes): array {
                $candidate = new Coordinate((float) $stop->lat, (float) $stop->lng);
                [$pickup, $dropoff] = $pair($candidate);
                $detour = PickupDetour::measureAll($this->geo, $offer, [$pickup, $dropoff]);

                return [
                    'suggestionId' => (string) $stop->getKey(),
                    'label' => LocalisedLabel::location($stop),
                    'lat' => $candidate->lat,
                    'lng' => $candidate->lng,
                    'addedMinutes' => (int) round($detour->addedMinutes),
                    'changeFromRequestedMinutes' => (int) round($detour->addedMinutes - $requestedMinutes),
                    'walkMeters' => $this->geo->distanceMeters($chosen, $candidate)->metres,
                    'withinLimit' => $detour->isWithin(),
                    'tier' => $detour->tier()?->value,
                    'extraMinutes' => $detour->extraMinutes(),
                    'refusalCode' => $this->refusal($offer, $pickup, $dropoff),
                ];
            })
            ->all();
    }

    private function refusal(CommuteOffer $offer, Coordinate $pickup, Coordinate $dropoff): ?string
    {
        try {
            RequestedPoints::assertUsablePair($this->geo, $offer, $pickup, $dropoff);
        } catch (DomainException $refused) {
            return $refused->errorCode->value;
        }

        return null;
    }
}
