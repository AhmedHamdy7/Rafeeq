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

    /**
     * The individual day bits this mask contains, in week order (Saturday first).
     *
     * Added for the per-weekday schedule model (Feature 03): a commute now carries a row per day,
     * and both the save action and the trip generator need to walk the mask rather than only ask
     * whether one day is in it. Week order rather than ascending value so that anything built from
     * this reads Saturday-to-Friday, the way the product talks about a week.
     *
     * @return array<int, int>
     */
    public function bits(): array
    {
        $order = [
            self::SATURDAY, self::SUNDAY, self::MONDAY, self::TUESDAY,
            self::WEDNESDAY, self::THURSDAY, self::FRIDAY,
        ];

        return array_values(array_filter($order, fn (int $bit): bool => $this->includes($bit)));
    }

    /**
     * A mask from day bits — the inverse of {@see bits()}, so the derived `days_mask` on a schedule
     * is a sum of its weekday rows rather than a second thing to keep in step by hand.
     *
     * @param  array<int, int>  $bits
     */
    public static function fromBitList(array $bits): self
    {
        return self::fromBits(array_reduce($bits, fn (int $carry, int $bit): int => $carry | $bit, 0));
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
