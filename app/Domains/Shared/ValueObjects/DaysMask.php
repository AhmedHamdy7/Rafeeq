<?php

namespace App\Domains\Shared\ValueObjects;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Bitmask for the Egyptian work week (Bible §4, `commute_schedules.days_mask`):
 * Saturday=1, Sunday=2, Monday=4, Tuesday=8, Wednesday=16, Thursday=32,
 * Friday=64. Stored as a plain tinyint so `days_mask & 2 > 0` can use an
 * index — far cheaper than `JSON_CONTAINS`.
 */
final readonly class DaysMask
{
    public const int SATURDAY = 1;

    public const int SUNDAY = 2;

    public const int MONDAY = 4;

    public const int TUESDAY = 8;

    public const int WEDNESDAY = 16;

    public const int THURSDAY = 32;

    public const int FRIDAY = 64;

    private const int ALL_DAYS = self::SATURDAY | self::SUNDAY | self::MONDAY
        | self::TUESDAY | self::WEDNESDAY | self::THURSDAY | self::FRIDAY;

    private function __construct(public int $value) {}

    public static function fromBits(int $value): self
    {
        if ($value < 0 || $value > self::ALL_DAYS) {
            throw new InvalidArgumentException("Invalid days mask: {$value}");
        }

        return new self($value);
    }

    /**
     * @param  list<int>  $bits
     */
    public static function fromDays(array $bits): self
    {
        return self::fromBits(array_reduce($bits, fn (int $carry, int $bit) => $carry | $bit, 0));
    }

    public static function weekdaysSunToThu(): self
    {
        return self::fromDays([self::SUNDAY, self::MONDAY, self::TUESDAY, self::WEDNESDAY, self::THURSDAY]);
    }

    public function includes(int $bit): bool
    {
        return ($this->value & $bit) > 0;
    }

    public function includesDate(CarbonInterface $date): bool
    {
        return $this->includes(self::bitForDate($date));
    }

    public static function bitForDate(CarbonInterface $date): int
    {
        // Carbon's dayOfWeek: 0 = Sunday .. 6 = Saturday.
        return match ($date->dayOfWeek) {
            6 => self::SATURDAY,
            0 => self::SUNDAY,
            1 => self::MONDAY,
            2 => self::TUESDAY,
            3 => self::WEDNESDAY,
            4 => self::THURSDAY,
            5 => self::FRIDAY,
        };
    }
}
