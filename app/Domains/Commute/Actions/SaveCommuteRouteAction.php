<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Support\RouteSettings;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use Illuminate\Support\Facades\DB;

/**
 * The route of a commute: where it starts, where it ends, and where it is
 * willing to stop (Chapter 4 §3).
 *
 * Locations are replaced wholesale rather than patched. A route is one thing,
 * not a set of independent points — a partial update could leave an origin from
 * one journey with a destination from another, and the sequence numbers would
 * have to be reconciled anyway.
 *
 * Sequence follows the ERD: origin is 0, destination is 999, and pickups sit in
 * between in the order the driver listed them. Leaving a large gap means a
 * pickup can be inserted later without renumbering everything after it.
 */
final readonly class SaveCommuteRouteAction
{
    private const int DESTINATION_SEQUENCE = 999;

    public function __construct(private GeoQueryEngine $geo) {}

    /**
     * @param  array<string, mixed>  $route  validated upstream: origin, destination,
     *                                       and optional pickups/dropoffs
     */
    public function execute(CommuteOffer $offer, array $route): CommuteOffer
    {
        CommuteState::assertEditable($offer);

        $origin = new Coordinate((float) $route['origin']['lat'], (float) $route['origin']['lng']);
        $destination = new Coordinate((float) $route['destination']['lat'], (float) $route['destination']['lng']);

        $this->assertDistinct($origin, $destination);

        $pickups = $route['pickups'] ?? [];
        $dropoffs = $route['dropoffs'] ?? [];

        $this->assertWithinLimits($pickups, $dropoffs);
        $this->assertPickupsAreOnTheWay($origin, $destination, $pickups);

        return DB::transaction(function () use ($offer, $route, $pickups, $dropoffs): CommuteOffer {
            $offer->locations()->delete();

            $this->store($offer, CommuteLocationType::Origin, $route['origin'], 0);

            $sequence = 1;

            foreach ($pickups as $pickup) {
                $this->store($offer, CommuteLocationType::Pickup, $pickup, $sequence++);
            }

            foreach ($dropoffs as $dropoff) {
                $this->store($offer, CommuteLocationType::Dropoff, $dropoff, $sequence++);
            }

            $this->store($offer, CommuteLocationType::Destination, $route['destination'], self::DESTINATION_SEQUENCE);

            // Cleared, not recomputed: the stored route and bounding box belong
            // to the journey as published. Recomputing here would spend a
            // provider call on a draft the driver is still editing, and publish
            // computes it anyway.
            $offer->forceFill([
                'route_polyline' => null,
                'route_distance_meters' => null,
                'route_duration_seconds' => null,
                'bbox_min_lat' => null,
                'bbox_max_lat' => null,
                'bbox_min_lng' => null,
                'bbox_max_lng' => null,
            ])->save();

            return $offer->load('locations');
        });
    }

    /**
     * Chapter 4 §3: "origin cannot equal destination". Compared by distance
     * rather than by exact coordinates, because two pins a few metres apart are
     * the same place as far as a journey is concerned.
     */
    private function assertDistinct(Coordinate $origin, Coordinate $destination): void
    {
        if ($this->geo->distanceMeters($origin, $destination)->isWithin(Distance::fromMetres(200))) {
            throw DomainException::of(ErrorCode::CommuteRouteInvalid, fields: [
                'route' => [__('commute.route.origin_equals_destination')],
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $pickups
     * @param  array<int, array<string, mixed>>  $dropoffs
     */
    private function assertWithinLimits(array $pickups, array $dropoffs): void
    {
        // Admin settings (COM-31: 3 + 3), not config: the partners move these without a deploy.
        if (count($pickups) > RouteSettings::maxPickupStops()
            || count($dropoffs) > RouteSettings::maxDropoffStops()) {
            throw DomainException::of(ErrorCode::CommuteRouteInvalid, fields: [
                'route' => [__('commute.route.too_many_stops')],
            ]);
        }
    }

    /**
     * Chapter 4 §3: "each pickup must be on the route within tolerance".
     *
     * The tolerance is the platform's whole-trip detour cap (COM-31,
     * `commute.max_trip_detour_minutes`). It used to be the driver's own
     * `max_detour_minutes`; the partners took that number away from drivers on
     * 2026-10-31 (C-1), because the time a detour costs is paid by the passengers
     * already in the car, not by the driver who chose it.
     *
     * @param  array<int, array<string, mixed>>  $pickups
     */
    private function assertPickupsAreOnTheWay(
        Coordinate $origin,
        Coordinate $destination,
        array $pickups,
    ): void {
        if ($pickups === []) {
            return;
        }

        // One route call for the whole check, not one per pickup: pitfall #16 is
        // exactly this loop calling a provider each time round.
        $direct = $this->geo->routeBetween($origin, $destination);
        $maxDetourMinutes = RouteSettings::maxTripDetourMinutes();

        foreach ($pickups as $index => $pickup) {
            $point = new Coordinate((float) $pickup['lat'], (float) $pickup['lng']);

            if ($this->geo->detourMinutes($direct, $point) > $maxDetourMinutes) {
                throw DomainException::of(ErrorCode::CommuteRouteInvalid, fields: [
                    "pickups.{$index}" => [__('commute.route.pickup_off_route', [
                        'minutes' => $maxDetourMinutes,
                    ])],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function store(CommuteOffer $offer, CommuteLocationType $type, array $input, int $sequence): void
    {
        $location = new CommuteLocation;

        $location->fill([
            'commute_offer_id' => $offer->id,
            'place_id' => $input['placeId'] ?? null,
            'type' => $type->value,
            'point' => new Coordinate((float) $input['lat'], (float) $input['lng']),
            'lat' => $input['lat'],
            'lng' => $input['lng'],
            'address' => $input['address'] ?? null,
            'sequence' => $sequence,
        ]);

        $location->save();
    }
}
