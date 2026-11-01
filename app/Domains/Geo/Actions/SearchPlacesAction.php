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
     * The catalogue place nearest a point, if one is close enough to name it by (DISCOVER-12).
     *
     * The reverse lookup behind a dropped pin, a recent search and a demand that arrived with
     * coordinates and no label. A curated place rather than a street address from a geocoder: the
     * catalogue is what both people already call that corner, and it costs nothing to ask.
     *
     * Null rather than the nearest place ten kilometres away. A label that names somewhere she is
     * not is worse than none, because the driver reads it as where to stop.
     */
    public function nearest(Coordinate $point, ?Distance $within = null): ?Place
    {
        // A full page rather than `limit: 1`: the shortlist is cut by popularity before it is
        // sorted by distance, and the most used place in the box is not the one under the pin.
        return $this->execute(near: $point, within: $within ?? Distance::fromMetres(self::NAMING_RADIUS_METRES))->first();
    }

    /**
     * How close a catalogue place must be to name a point after it. About six minutes' walk: the
     * distance at which "near Smart Village B6" still tells somebody where to look.
     */
    public const int NAMING_RADIUS_METRES = 500;

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
