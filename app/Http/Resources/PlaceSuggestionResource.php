<?php

namespace App\Http\Resources;

use App\Domains\Geo\Models\PlaceSuggestion;

final class PlaceSuggestionResource
{
    /**
     * @return array{id: string, kind: string, label: string|null, lat: float, lng: float, googlePlaceId: string|null, area: array{placeId: string, name: string|null, city: string|null}|null, distanceMetres: int|null, uses: int}
     */
    public static function make(PlaceSuggestion $row, ?int $distanceMetres): array
    {
        return [
            'id' => (string) $row->id,
            'kind' => $row->kind->value,
            'label' => $row->localLabel(),
            'lat' => (float) $row->lat,
            'lng' => (float) $row->lng,
            'googlePlaceId' => $row->google_place_id,
            'area' => $row->area?->toPoint(),
            'distanceMetres' => $distanceMetres,
            'uses' => (int) $row->uses,
        ];
    }
}
