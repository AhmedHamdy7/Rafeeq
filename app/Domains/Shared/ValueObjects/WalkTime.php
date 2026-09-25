<?php

namespace App\Domains\Shared\ValueObjects;

use InvalidArgumentException;

/**
 * Minutes on foot.
 *
 * Deliberately NOT interchangeable with {@see Distance} — see the note there
 * on pitfall #19. A passenger's tolerance is expressed in minutes because that
 * is how people think about walking to a meeting point; the conversion lives in
 * exactly one place so it cannot drift.
 */
final readonly class WalkTime
{
    private function __construct(public int $minutes) {}

    public static function fromMinutes(int $minutes): self
    {
        if ($minutes < 0) {
            throw new InvalidArgumentException("Walk time cannot be negative: {$minutes}");
        }

        return new self($minutes);
    }

    public function isWithin(self $limit): bool
    {
        return $this->minutes <= $limit->minutes;
    }
}
