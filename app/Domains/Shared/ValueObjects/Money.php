<?php

namespace App\Domains\Shared\ValueObjects;

use InvalidArgumentException;

/**
 * Every amount in Rafeeq is an integer number of piastres — never a float,
 * never a decimal column. Engineering Bible §3.5 / pitfall #35: floats lose
 * cents (88.20 * 3 = 264.59999999999997), and this type makes that class of
 * bug impossible to write by accident.
 */
final readonly class Money
{
    private function __construct(public int $piastres) {}

    public static function fromPiastres(int $piastres): self
    {
        if ($piastres < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }

        return new self($piastres);
    }

    public static function fromPounds(float $pounds): self
    {
        return self::fromPiastres((int) round($pounds * 100));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self $other): self
    {
        return new self($this->piastres + $other->piastres);
    }

    public function minus(self $other): self
    {
        return self::fromPiastres($this->piastres - $other->piastres);
    }

    public function percentage(float $percent): self
    {
        return new self((int) round($this->piastres * $percent / 100));
    }

    public function isZero(): bool
    {
        return $this->piastres === 0;
    }

    public function equals(self $other): bool
    {
        return $this->piastres === $other->piastres;
    }

    public function toPounds(): float
    {
        return $this->piastres / 100;
    }

    public function format(): string
    {
        return number_format($this->toPounds(), 2).' ج.م';
    }
}
