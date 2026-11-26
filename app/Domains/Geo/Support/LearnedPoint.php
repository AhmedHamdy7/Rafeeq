<?php

namespace App\Domains\Geo\Support;

use App\Domains\Shared\ValueObjects\Coordinate;

final readonly class LearnedPoint
{
    public const int BOARD = 1;

    public const int ALIGHT = 2;

    public function __construct(
        public Coordinate $point,
        public ?string $label,
        public ?string $googlePlaceId,
        public ?string $areaId,
        public int $role,
        public bool $isCommutePoint = false,
        public ?string $userId = null,
    ) {}

    public function isPublic(): bool
    {
        return $this->isCommutePoint || $this->googlePlaceId !== null;
    }
}
