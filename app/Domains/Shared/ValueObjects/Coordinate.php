<?php

namespace App\Domains\Shared\ValueObjects;

use InvalidArgumentException;

/**
 * A WGS84 (SRID 4326) lat/lng pair. Rounding to 4 decimal places (~11m) is
 * what makes `route_cache` share hits across nearby searches — see
 * RAFEEQ_ERD.md §6 and Bible §7.2 on the Google Maps cost problem.
 */
final readonly class Coordinate
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {
        if ($lat < -90.0 || $lat > 90.0) {
            throw new InvalidArgumentException("Latitude out of range: {$lat}");
        }

        if ($lng < -180.0 || $lng > 180.0) {
            throw new InvalidArgumentException("Longitude out of range: {$lng}");
        }
    }

    public function roundedTo(int $decimals = 4): self
    {
        return new self(round($this->lat, $decimals), round($this->lng, $decimals));
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function toArray(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }

    public function toWkt(): string
    {
        return "POINT({$this->lng} {$this->lat})";
    }
}
