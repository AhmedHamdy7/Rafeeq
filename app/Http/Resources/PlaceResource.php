<?php

namespace App\Http\Resources;

use App\Domains\Geo\Models\Place;
use App\Domains\Geo\Support\Haversine;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A place in the shared catalogue.
 *
 * Both names are returned rather than one picked by locale: a map label and a
 * search result are often shown together, and the client decides which script
 * suits the surface it is drawing.
 *
 * @mixin Place
 */
final class PlaceResource extends JsonResource
{
    /** Metres from the point asked about, when the list was asked for near one. */
    private ?int $distanceMetres = null;

    /**
     * The list, each place with how far it is from `near` (DISCOVER-12) — the reverse lookup a
     * dropped pin needs: "Smart Village · Building B12, 40 m away" is a label she can accept or
     * change, and the first row is the closest.
     *
     * @param  iterable<Place>  $places
     */
    public static function near(iterable $places, ?Coordinate $near): AnonymousResourceCollection
    {
        $rows = [];

        foreach ($places as $place) {
            $row = new self($place);

            if ($near !== null) {
                $row->distanceMetres = Haversine::between($near, new Coordinate((float) $place->lat, (float) $place->lng))->metres;
            }

            $rows[] = $row;
        }

        return new AnonymousResourceCollection($rows, self::class);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'nameAr' => $this->name_ar,
            'type' => $this->type->value,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'city' => $this->city,
            'district' => $this->district,
            // Only when `lat` / `lng` were sent: how far this place is from that point.
            'distanceMetres' => $this->when($this->distanceMetres !== null, fn (): int => (int) $this->distanceMetres),
        ];
    }
}
