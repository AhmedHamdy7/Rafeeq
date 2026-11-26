<?php

namespace App\Domains\Geo\Support;

use App\Domains\Booking\Enums\PointSource;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PlaceLearning
{
    public static function register(): void
    {
        CommuteOffer::updated(fn (CommuteOffer $offer) => self::safely(function () use ($offer): void {
            if ($offer->getOriginal('published_at') !== null || $offer->published_at === null) {
                return;
            }

            PlaceSuggestionBook::learnLater($offer->locations()->get()->map(self::fromLocation(...))->all());
        }));

        CommuteLocation::created(fn (CommuteLocation $location) => self::safely(function () use ($location): void {
            $status = CommuteOffer::query()->whereKey($location->commute_offer_id)->value('status');

            if ($status !== CommuteOfferStatus::Published->value && $status !== CommuteOfferStatus::Paused->value) {
                return;
            }

            PlaceSuggestionBook::learnLater([self::fromLocation($location)]);
        }));

        SeatRequest::created(fn (SeatRequest $request) => self::safely(function () use ($request): void {
            $points = [];

            foreach (['pickup' => LearnedPoint::BOARD, 'dropoff' => LearnedPoint::ALIGHT] as $end => $role) {
                $raw = self::attr($request, "requested_{$end}_source");
                $source = $raw instanceof PointSource ? $raw : PointSource::tryFrom((string) $raw);

                if (! in_array($source, [PointSource::CustomMap, PointSource::DriverSuggestion], true)) {
                    continue;
                }

                $lat = self::attr($request, "requested_{$end}_lat");
                $lng = self::attr($request, "requested_{$end}_lng");

                if ($lat === null || $lng === null) {
                    continue;
                }

                $points[] = new LearnedPoint(
                    point: new Coordinate((float) $lat, (float) $lng),
                    label: self::attr($request, "requested_{$end}_label"),
                    googlePlaceId: self::attr($request, "requested_{$end}_google_place_id"),
                    areaId: self::attr($request, "requested_{$end}_area_id"),
                    role: $role,
                    isCommutePoint: $source === PointSource::DriverSuggestion,
                    userId: $request->passenger_user_id,
                );
            }

            PlaceSuggestionBook::learnLater($points);
        }));

        PickupPointRequest::created(fn (PickupPointRequest $pickup) => self::safely(function () use ($pickup): void {
            $point = $pickup->proposed_point;

            if (! $point instanceof Coordinate) {
                return;
            }

            PlaceSuggestionBook::learnLater([new LearnedPoint(
                point: $point,
                label: self::attr($pickup, 'proposed_label'),
                googlePlaceId: self::attr($pickup, 'proposed_google_place_id'),
                areaId: self::attr($pickup, 'proposed_area_id'),
                role: LearnedPoint::BOARD,
                userId: $pickup->requested_by_user_id,
            )]);
        }));

        CommuteDemand::created(fn (CommuteDemand $demand) => self::safely(function () use ($demand): void {
            PlaceSuggestionBook::learnLater([
                new LearnedPoint(
                    point: new Coordinate((float) $demand->origin_lat, (float) $demand->origin_lng),
                    label: self::attr($demand, 'origin_label'),
                    googlePlaceId: self::attr($demand, 'origin_google_place_id'),
                    areaId: self::attr($demand, 'origin_area_id'),
                    role: LearnedPoint::BOARD,
                    userId: $demand->passenger_user_id,
                ),
                new LearnedPoint(
                    point: new Coordinate((float) $demand->dest_lat, (float) $demand->dest_lng),
                    label: self::attr($demand, 'destination_label'),
                    googlePlaceId: self::attr($demand, 'destination_google_place_id'),
                    areaId: self::attr($demand, 'destination_area_id'),
                    role: LearnedPoint::ALIGHT,
                    userId: $demand->passenger_user_id,
                ),
            ]);
        }));
    }

    private static function safely(callable $learn): void
    {
        try {
            $learn();
        } catch (Throwable $e) {
            Log::warning('places.suggestions.learn_failed', ['reason' => $e->getMessage()]);
        }
    }

    private static function attr(Model $model, string $key): mixed
    {
        return $model->getAttributes()[$key] ?? null;
    }

    private static function fromLocation(CommuteLocation $location): LearnedPoint
    {
        return new LearnedPoint(
            point: new Coordinate((float) $location->lat, (float) $location->lng),
            label: self::attr($location, 'address'),
            googlePlaceId: self::attr($location, 'google_place_id'),
            areaId: self::attr($location, 'area_id'),
            role: in_array($location->type, [CommuteLocationType::Origin, CommuteLocationType::Pickup], true)
                ? LearnedPoint::BOARD
                : LearnedPoint::ALIGHT,
            isCommutePoint: true,
        );
    }
}
