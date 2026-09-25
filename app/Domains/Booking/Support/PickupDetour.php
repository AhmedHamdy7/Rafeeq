<?php

namespace App\Domains\Booking\Support;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;

/**
 * 🔴 What one extra stop actually costs the driver.
 *
 * The two numbers this produces, `added_minutes` and `added_km`, are the ones the
 * migration marks "computed by us, never claimed by the requester" — and the ones
 * the driver's `max_detour_minutes` is checked against. A passenger who could
 * supply them would be deciding how far out of their way the driver goes.
 *
 * The comparison is against the commute's route **as published**, including the
 * pickups the driver already agreed to. Comparing against a bare origin-to-
 * destination line instead would be worse than useless: a commute that already
 * detours for two existing passengers would make a third stop look like it SAVES
 * time, because the line it was measured against was never the journey.
 *
 * One route call, not one per candidate (pitfall #16). The published route was
 * paid for at publish time and is reused from the database; the single call here
 * asks the one question that cannot be answered from it — what the journey becomes
 * with the new stop in it.
 */
final readonly class PickupDetour
{
    private function __construct(
        public float $addedMinutes,
        public float $addedKm,
    ) {}

    public static function measure(GeoQueryEngine $geo, CommuteOffer $offer, Coordinate $proposed): self
    {
        $published = self::publishedRoute($offer);

        $origin = self::pointOf($offer, CommuteLocationType::Origin);
        $destination = self::pointOf($offer, CommuteLocationType::Destination);

        $via = self::existingPickups($offer);
        $via[] = $proposed;

        $withStop = $geo->routeBetween($origin, $destination, $via);

        /*
         * Never negative. A straight-line engine, and a real provider recomputing
         * a route on live traffic, can both hand back a journey that is nominally
         * quicker with the stop in it. Reporting that as a negative detour would
         * mean a passenger's request appears to give the driver time back, which
         * is not a claim this system should ever make on the strength of an
         * estimate.
         */
        return new self(
            addedMinutes: round(max(0, $withStop->durationSeconds - $published->durationSeconds) / 60, 1),
            addedKm: round(max(0, $withStop->distance->metres - $published->distance->metres) / 1000, 2),
        );
    }

    /**
     * The driver's stated limit, applied as a refusal rather than a warning.
     *
     * `max_detour_minutes` is the number they chose to say how far out of their way
     * they will go, and it is already used this way everywhere else: the search
     * drops commutes whose detour exceeds it, and saving a route refuses a pickup
     * beyond it. A request that quietly arrived over the limit would ask a driver
     * to agree to something they had already said no to.
     */
    public function assertWithin(int $maxDetourMinutes): void
    {
        if ($this->addedMinutes > $maxDetourMinutes) {
            throw DomainException::of(ErrorCode::PickupDetourTooLong, fields: [
                'addedMinutes' => [(string) $this->addedMinutes],
                'maxDetourMinutes' => [(string) $maxDetourMinutes],
            ]);
        }
    }

    /**
     * The route as published. A commute with no stored route has not been
     * published, so there is nothing to measure a detour against — and a published
     * commute always has one.
     */
    private static function publishedRoute(CommuteOffer $offer): Route
    {
        if ($offer->route_polyline === null) {
            throw DomainException::of(ErrorCode::PickupNotOnCommute);
        }

        return new Route(
            polyline: $offer->route_polyline,
            distance: Distance::fromMetres((int) $offer->route_distance_meters),
            durationSeconds: (int) $offer->route_duration_seconds,
        );
    }

    /**
     * @return array<int, Coordinate>
     */
    private static function existingPickups(CommuteOffer $offer): array
    {
        $via = [];

        foreach ($offer->locations->where('type', CommuteLocationType::Pickup)->sortBy('sequence') as $pickup) {
            $via[] = new Coordinate((float) $pickup->lat, (float) $pickup->lng);
        }

        return $via;
    }

    private static function pointOf(CommuteOffer $offer, CommuteLocationType $type): Coordinate
    {
        $location = $offer->locations->firstWhere('type', $type);

        if ($location === null) {
            throw DomainException::of(ErrorCode::PickupNotOnCommute);
        }

        return new Coordinate((float) $location->lat, (float) $location->lng);
    }
}
