<?php

namespace App\Console\Commands;

use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Geo\Models\Area;
use App\Domains\Geo\Support\AreaRegistry;
use App\Domains\Geo\Support\GoogleGeocoder;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\SearchHistory;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Fills the Google area of every stored point that has none (review item 8): commute starts, ends
 * and suggested points, demand ends, seat-request points, pickup-change points and recent searches.
 * Needs `GOOGLE_MAPS_SERVER_KEY`. Safe to repeat.
 */
final class BackfillPointAreas extends Command
{
    protected $signature = 'areas:backfill';

    protected $description = 'Reverse-geocode every stored point without a Google area and store its area.';

    private int $filled = 0;

    public function handle(AreaRegistry $areas, GoogleGeocoder $geocoder): int
    {
        if (! $geocoder->available()) {
            $this->error('Set GOOGLE_MAPS_SERVER_KEY first.');

            return self::FAILURE;
        }

        $this->fill(CommuteLocation::query(), $areas, ['area_id' => ['lat', 'lng']]);
        $this->fill(CommuteDemand::query(), $areas, [
            'origin_area_id' => ['origin_lat', 'origin_lng'],
            'destination_area_id' => ['dest_lat', 'dest_lng'],
        ]);
        $this->fill(SeatRequest::query(), $areas, [
            'requested_pickup_area_id' => ['requested_pickup_lat', 'requested_pickup_lng'],
            'requested_dropoff_area_id' => ['requested_dropoff_lat', 'requested_dropoff_lng'],
        ]);
        $this->fill(PickupPointRequest::query(), $areas, ['proposed_area_id' => 'proposed_point']);
        $this->fillRecentSearches($areas);

        $this->info("Filled {$this->filled} point areas.");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, array{0: string, 1: string}|string>  $columns
     */
    private function fill(Builder $query, AreaRegistry $areas, array $columns): void
    {
        $query->where(function (Builder $missing) use ($columns): void {
            foreach (array_keys($columns) as $column) {
                $missing->orWhereNull($column);
            }
        })->chunkById(200, function ($rows) use ($areas, $columns): void {
            foreach ($rows as $row) {
                $changes = [];

                foreach ($columns as $column => $source) {
                    $point = $this->pointOf($row, $source);

                    if ($row->{$column} !== null || $point === null) {
                        continue;
                    }

                    $id = $areas->serverArea($point);

                    if ($id !== null) {
                        $changes[$column] = $id;
                        $this->filled++;
                    }
                }

                if ($changes !== []) {
                    $row->forceFill($changes)->saveQuietly();
                }
            }
        });
    }

    /**
     * @param  array{0: string, 1: string}|string  $source
     */
    private function pointOf(Model $row, array|string $source): ?Coordinate
    {
        if (is_string($source)) {
            $point = $row->{$source};

            return $point instanceof Coordinate ? $point : null;
        }

        [$lat, $lng] = $source;

        return $row->{$lat} === null || $row->{$lng} === null
            ? null
            : new Coordinate((float) $row->{$lat}, (float) $row->{$lng});
    }

    private function fillRecentSearches(AreaRegistry $areas): void
    {
        SearchHistory::query()->chunkById(200, function ($rows) use ($areas): void {
            foreach ($rows as $row) {
                $criteria = $row->criteria;
                $changed = false;

                foreach (['origin', 'destination'] as $end) {
                    if (isset($criteria[$end]['area']['placeId']) || ! isset($criteria[$end]['lat'], $criteria[$end]['lng'])) {
                        continue;
                    }

                    $id = $areas->serverArea(new Coordinate((float) $criteria[$end]['lat'], (float) $criteria[$end]['lng']));

                    if ($id !== null) {
                        $criteria[$end]['area'] = Area::pointOf($id);
                        $changed = true;
                        $this->filled++;
                    }
                }

                if ($changed) {
                    $row->forceFill(['criteria' => $criteria])->saveQuietly();
                }
            }
        });
    }
}
