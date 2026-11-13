<?php

namespace App\Domains\Admin\Support;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Geo\Models\Place;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Statistics filters (T03): the area a commute starts in, and the car tier. Applied to
 * commutes, so every number that comes from a commute — its days, its bookings, its waiting
 * list — follows. Figures that have no commute (sign-ups, ledger money) ignore it, and the
 * page says so.
 */
final readonly class StatisticsFilter
{
    public function __construct(
        public string $area = '',
        public string $tier = '',
    ) {}

    public static function of(string $area, string $tier): self
    {
        return new self(
            in_array($area, self::areas(), true) ? $area : '',
            VehicleTier::tryFrom($tier) !== null ? $tier : '',
        );
    }

    public function isActive(): bool
    {
        return $this->area !== '' || $this->tier !== '';
    }

    /**
     * Narrow a commute_offers query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $offers
     * @return Builder<TModel>
     */
    public function offers(Builder $offers): Builder
    {
        return $offers
            ->when($this->area !== '', fn (Builder $query) => $query->whereHas('locations', fn (Builder $location) => $location
                ->where('type', CommuteLocationType::Origin->value)
                ->whereHas('place', fn (Builder $place) => $place->where('district', $this->area))))
            ->when($this->tier !== '', fn (Builder $query) => $query->whereHas('vehicle', fn (Builder $vehicle) => $vehicle->where('tier', $this->tier)));
    }

    /**
     * The areas commutes start in, for the filter's list.
     *
     * @return list<string>
     */
    public static function areas(): array
    {
        return Place::query()
            ->whereIn('id', CommuteLocation::query()->select('place_id')->where('type', CommuteLocationType::Origin->value)->whereNotNull('place_id'))
            ->whereNotNull('district')
            ->distinct()
            ->orderBy('district')
            ->limit(200)
            ->pluck('district')
            ->map(fn ($district) => (string) $district)
            ->values()
            ->all();
    }
}
