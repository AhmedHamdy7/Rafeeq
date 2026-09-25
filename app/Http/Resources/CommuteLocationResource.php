<?php

namespace App\Http\Resources;

use App\Domains\Commute\Models\CommuteLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One point on a commute's route.
 *
 * The plain `lat`/`lng` columns are returned rather than the spatial one: they
 * exist precisely so nothing outside the Geo domain needs a spatial function,
 * and they are what a map draws from.
 *
 * @mixin CommuteLocation
 */
final class CommuteLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'address' => $this->address,
            'placeId' => $this->place_id,
            // Origin is 0 and destination 999, with stops in between in the
            // order they are reached.
            'sequence' => $this->sequence,
        ];
    }
}
