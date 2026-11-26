<?php

namespace App\Domains\Geo\Actions;

use App\Domains\Geo\Models\PlaceSuggestion;
use App\Domains\Geo\Support\Haversine;
use App\Domains\Geo\Support\LearnedPoint;
use App\Domains\Geo\Support\PlaceNameMatch;
use App\Domains\Geo\Support\SuggestionBox;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Support\Collection;

final class SuggestPlacesAction
{
    private const int CANDIDATES = 300;

    private const int NEAR_BUCKET_METRES = 300;

    private const int SEARCH_BUCKET_METRES = 2000;

    /**
     * @return Collection<int, array{row: PlaceSuggestion, distance: int|null}>
     */
    public function execute(?Coordinate $near, ?string $term, ?string $kind, int $limit): Collection
    {
        $needle = $term === null ? '' : PlaceNameMatch::normalise($term);
        $query = PlaceSuggestion::query()->whereNotNull('lat')->whereNotNull('lng')->with('area');

        if ($needle !== '') {
            $query->where('search_text', 'like', '%'.strtr($needle, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%');
        }

        if ($near !== null) {
            $radius = (int) config($needle === '' ? 'rafeeq.places.nearby_radius_metres' : 'rafeeq.places.search_radius_metres');
            SuggestionBox::around($query, $near, $radius);
        }

        $role = match ($kind) {
            'start', 'pickup' => LearnedPoint::BOARD,
            'end', 'dropoff' => LearnedPoint::ALIGHT,
            default => 0,
        };

        $rows = $query->orderByDesc('uses')->limit(self::CANDIDATES)->get()
            ->map(fn (PlaceSuggestion $row): array => [
                'row' => $row,
                'distance' => $near === null ? null : (int) round(Haversine::between($near, new Coordinate((float) $row->lat, (float) $row->lng))->metres),
            ]);

        if ($near !== null) {
            $radius = (int) config($needle === '' ? 'rafeeq.places.nearby_radius_metres' : 'rafeeq.places.search_radius_metres');
            $rows = $rows->filter(fn (array $item): bool => $item['distance'] <= $radius);
        }

        $bucket = $needle === '' ? self::NEAR_BUCKET_METRES : self::SEARCH_BUCKET_METRES;

        return $rows
            ->sort(function (array $a, array $b) use ($needle, $role, $bucket): int {
                return $this->rank($a, $needle, $role, $bucket) <=> $this->rank($b, $needle, $role, $bucket);
            })
            ->take($limit)
            ->values();
    }

    /**
     * @param  array{row: PlaceSuggestion, distance: int|null}  $item
     * @return list<int|float>
     */
    private function rank(array $item, string $needle, int $role, int $bucket): array
    {
        $row = $item['row'];
        $starts = 1;

        if ($needle !== '') {
            foreach ([$row->label, $row->label_ar] as $name) {
                if ($name !== null && str_starts_with(PlaceNameMatch::normalise($name), $needle)) {
                    $starts = 0;
                }
            }
        }

        return [
            $starts,
            $item['distance'] === null ? 0 : intdiv($item['distance'], $bucket),
            $role !== 0 && ($row->roles & $role) === 0 ? 1 : 0,
            -$row->uses,
            $item['distance'] ?? 0,
        ];
    }
}
