<?php

namespace App\Http\Resources;

use App\Domains\Geo\Models\Place;
use Illuminate\Http\Request;
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
        ];
    }
}
