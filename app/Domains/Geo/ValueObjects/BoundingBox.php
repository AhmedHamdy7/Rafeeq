<?php

namespace App\Domains\Geo\ValueObjects;

use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use InvalidArgumentException;

/**
 * The rectangle a route fits inside, which is what the search index actually
 * filters on (ERD §21: `commute_offers_search_index`).
 *
 * The box exists to answer "could this offer possibly be relevant?" with an
 * indexed integer comparison, before anything expensive runs. It must therefore
 * never be too SMALL: a false negative here means an offer a passenger is
 * standing next to is never even considered, and nothing downstream can
 * recover from that.
 *
 * Hence pitfall #18 — it is computed from every point of the route, not from
 * the endpoints. A road that bows north of both origin and destination would
 * otherwise fall outside its own box. A margin is then added on top, because a
 * passenger near the route is not exactly on it.
 */
final readonly class BoundingBox
{
    public function __construct(
        public float $minLat,
        public float $maxLat,
        public float $minLng,
        public float $maxLng,
    ) {
        if ($minLat > $maxLat || $minLng > $maxLng) {
            throw new InvalidArgumentException('Bounding box bounds are inverted.');
        }
    }

    /**
     * @param  array<int, Coordinate>  $points  every point of the route, not just its ends
     */
    public static function around(array $points, Distance $margin): self
    {
        if ($points === []) {
            throw new InvalidArgumentException('Cannot build a bounding box from no points.');
        }

        $lats = array_map(fn (Coordinate $point) => $point->lat, $points);
        $lngs = array_map(fn (Coordinate $point) => $point->lng, $points);

        $minLat = min($lats);
        $maxLat = max($lats);

        // A degree of latitude is ~111km everywhere; a degree of longitude
        // shrinks towards the poles, so the margin has to be divided by the
        // cosine of the latitude or the box is too narrow in the east-west
        // direction. At Cairo's latitude that is a ~14% difference — enough to
        // lose an offer at the edge.
        $latMargin = $margin->metres / 111_320;
        $lngMargin = $margin->metres / (111_320 * max(cos(deg2rad(($minLat + $maxLat) / 2)), 0.01));

        return new self(
            minLat: max($minLat - $latMargin, -90),
            maxLat: min($maxLat + $latMargin, 90),
            minLng: max(min($lngs) - $lngMargin, -180),
            maxLng: min(max($lngs) + $lngMargin, 180),
        );
    }

    public function contains(Coordinate $point): bool
    {
        return $point->lat >= $this->minLat
            && $point->lat <= $this->maxLat
            && $point->lng >= $this->minLng
            && $point->lng <= $this->maxLng;
    }

    /**
     * @return array{bbox_min_lat: float, bbox_max_lat: float, bbox_min_lng: float, bbox_max_lng: float}
     */
    public function toColumns(): array
    {
        return [
            'bbox_min_lat' => round($this->minLat, 7),
            'bbox_max_lat' => round($this->maxLat, 7),
            'bbox_min_lng' => round($this->minLng, 7),
            'bbox_max_lng' => round($this->maxLng, 7),
        ];
    }
}
