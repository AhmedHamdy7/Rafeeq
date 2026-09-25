<?php

namespace App\Domains\Geo\Actions;

use App\Domains\Geo\Models\Place;
use App\Domains\Geo\Support\Haversine;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finding a place by name, or near a point on the map (Chapter 4 §3: "support
 * map drag and search").
 *
 * The geographic filter is a bounding box on the duplicated `lat`/`lng` columns
 * rather than a spatial function on `point`. That is the pattern the whole
 * system uses and the reason those columns are duplicated at all: an indexed
 * range scan over two decimals costs almost nothing, while a distance function
 * in a WHERE clause cannot use an index and reads the table (pitfall #17).
 *
 * The box is a pre-filter, so the shortlist is then measured properly and sorted
 * by real distance — a box is a square and the question is a circle.
 */
final readonly class SearchPlacesAction
{
    private const int DEFAULT_LIMIT = 20;

    /**
     * @return Collection<int, Place>
     */
    public function execute(
        ?string $term = null,
        ?Coordinate $near = null,
        ?Distance $within = null,
        int $limit = self::DEFAULT_LIMIT,
    ): Collection {
        $query = Place::query()->where('is_public', true);

        if ($term !== null && trim($term) !== '') {
            $query->where(function ($query) use ($term): void {
                // Both scripts: the catalogue carries an Arabic and a Latin name
                // for every place, and people search in whichever they type in.
                $query->where('name', 'like', '%'.$term.'%')
                    ->orWhere('name_ar', 'like', '%'.$term.'%')
                    ->orWhere('district', 'like', '%'.$term.'%');
            });
        }

        if ($near !== null) {
            $radius = $within ?? Distance::fromKilometres(5);
            $box = $this->boxAround($near, $radius);

            $query->whereBetween('lat', [$box['minLat'], $box['maxLat']])
                ->whereBetween('lng', [$box['minLng'], $box['maxLng']]);
        }

        // Fetched wider than asked for when a distance sort will follow, since
        // the box keeps corners the circle does not.
        $places = $query
            ->orderByDesc('usage_count')
            ->limit($near !== null ? $limit * 3 : $limit)
            ->get();

        if ($near === null) {
            return $places;
        }

        $radius = $within ?? Distance::fromKilometres(5);

        return $places
            ->filter(fn (Place $place) => Haversine::between(
                $near,
                new Coordinate((float) $place->lat, (float) $place->lng),
            )->isWithin($radius))
            ->sortBy(fn (Place $place) => Haversine::between(
                $near,
                new Coordinate((float) $place->lat, (float) $place->lng),
            )->metres)
            ->take($limit)
            ->values();
    }

    /**
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    private function boxAround(Coordinate $centre, Distance $radius): array
    {
        $latDelta = $radius->metres / 111_320;
        // Longitude degrees shorten away from the equator; dividing by the
        // cosine keeps the box wide enough east-to-west.
        $lngDelta = $radius->metres / (111_320 * max(cos(deg2rad($centre->lat)), 0.01));

        return [
            'minLat' => $centre->lat - $latDelta,
            'maxLat' => $centre->lat + $latDelta,
            'minLng' => $centre->lng - $lngDelta,
            'maxLng' => $centre->lng + $lngDelta,
        ];
    }
}
