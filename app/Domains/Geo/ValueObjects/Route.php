<?php

namespace App\Domains\Geo\ValueObjects;

use App\Domains\Geo\Support\Polyline;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;

/**
 * A road route between two points: the line itself, how far it is, and how long
 * it takes.
 *
 * Distance and duration are kept apart on purpose (pitfall #20): the distance
 * between two places does not change, but how long it takes does. They are
 * cached with different lifetimes, and conflating them means either serving a
 * stale travel time or paying a provider again for a number that was never
 * going to move.
 */
final readonly class Route
{
    /**
     * @param  array<int, Coordinate>|null  $points  decoded lazily from the polyline when absent
     */
    public function __construct(
        public string $polyline,
        public Distance $distance,
        public int $durationSeconds,
        private ?array $points = null,
    ) {}

    /**
     * @return array<int, Coordinate>
     */
    public function points(): array
    {
        return $this->points ?? Polyline::decode($this->polyline);
    }

    /**
     * The box the search index filters on. Built from every point of the line
     * plus a margin — see {@see BoundingBox} for why an undersized box is the
     * one failure that cannot be recovered from downstream.
     */
    public function boundingBox(Distance $margin): BoundingBox
    {
        return BoundingBox::around($this->points(), $margin);
    }

    public function durationMinutes(): int
    {
        return (int) ceil($this->durationSeconds / 60);
    }
}
