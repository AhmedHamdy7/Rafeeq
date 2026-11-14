<?php

namespace App\Domains\Matching\Support;

use App\Domains\Geo\Models\Place;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;

final readonly class SearchArea
{
    public function __construct(
        public string $placeId,
        public Coordinate $centre,
        public Distance $radius,
    ) {}

    public static function of(?Place $place): ?self
    {
        $radius = $place?->areaRadius();

        if ($place === null || $radius === null) {
            return null;
        }

        return new self($place->id, new Coordinate((float) $place->lat, (float) $place->lng), $radius);
    }
}
