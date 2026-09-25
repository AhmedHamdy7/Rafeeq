<?php

namespace App\Domains\Shared\ValueObjects;

use InvalidArgumentException;

/**
 * A length, always in metres internally.
 *
 * It exists because of pitfall #19: the codebase carries both distances in
 * metres and walking limits in minutes, and `max_walk_minutes = 15` compared
 * against `distance = 1200` is a comparison that looks fine and is wrong by a
 * factor of eighty. Making them different types means the compiler refuses the
 * mistake instead of a reviewer having to spot it.
 */
final readonly class Distance
{
    private function __construct(public int $metres) {}

    public static function fromMetres(int|float $metres): self
    {
        if ($metres < 0) {
            throw new InvalidArgumentException("Distance cannot be negative: {$metres}");
        }

        return new self((int) round($metres));
    }

    public static function fromKilometres(int|float $kilometres): self
    {
        return self::fromMetres($kilometres * 1000);
    }

    public function kilometres(): float
    {
        return $this->metres / 1000;
    }

    /**
     * How long this takes to walk, at the pace an average person actually
     * manages on a city street rather than a treadmill figure — 5 km/h, the
     * value the walking limits in `commute_offers.max_walk_minutes` assume.
     */
    public function asWalkTime(): WalkTime
    {
        return WalkTime::fromMinutes((int) ceil($this->metres / 1000 / 5 * 60));
    }

    public function isWithin(self $other): bool
    {
        return $this->metres <= $other->metres;
    }
}
