<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Models\RouteCache;
use App\Domains\Geo\Support\CachingGeoEngine;
use App\Domains\Geo\Support\DrawableRoad;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use Illuminate\Database\Eloquent\Builder;

final readonly class RefreshCommuteRoadAction
{
    public function __construct(private GeoQueryEngine $geo) {}

    /**
     * @return Builder<CommuteOffer>
     */
    public static function due(): Builder
    {
        return CommuteOffer::query()
            ->whereIn('status', [CommuteOfferStatus::Published->value, CommuteOfferStatus::Paused->value])
            ->whereNotNull('route_polyline')
            ->where(fn (Builder $query) => $query
                ->whereNull('route_fetched_at')
                ->orWhere('route_fetched_at', '<', now()->subDays(DrawableRoad::refreshDays())));
    }

    public function execute(CommuteOffer $offer): bool
    {
        $offer->loadMissing('locations');

        $origin = $this->pointOf($offer, CommuteLocationType::Origin);
        $destination = $this->pointOf($offer, CommuteLocationType::Destination);

        if ($origin === null || $destination === null) {
            return false;
        }

        RouteCache::query()->whereKey(CachingGeoEngine::cacheKey($origin, $destination))->delete();

        $route = $this->geo->routeBetween($origin, $destination);

        if (DrawableRoad::of($route) === null) {
            return false;
        }

        $box = $route->boundingBox(Distance::fromMetres((int) config('rafeeq.geo.search_margin_metres')));

        $offer->forceFill([
            'route_polyline' => $route->polyline,
            'route_fetched_at' => now(),
            ...$box->toColumns(),
        ])->saveQuietly();

        return true;
    }

    private function pointOf(CommuteOffer $offer, CommuteLocationType $type): ?Coordinate
    {
        $location = $offer->locations->firstWhere('type', $type);

        return $location === null ? null : new Coordinate((float) $location->lat, (float) $location->lng);
    }
}
