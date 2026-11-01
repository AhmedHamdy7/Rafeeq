<?php

namespace App\Domains\Payment\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The settlement week: Saturday 00:00 → Friday 23:59, Cairo time (PAY-11, decision D-0006).
 *
 * 🔴 Cairo, not UTC. A commission earned on a Friday evening in Cairo is still that week's
 * commission, and a deadline at "Friday 23:59" means the driver's Friday — computed in UTC it would
 * land two or three hours early (and move with daylight saving).
 *
 * A week is named by its Saturday as a `Y-m-d` string; that is what `debt_entries.week_of` stores.
 * The start day is fixed rather than a setting: the whole product's day bitmask starts at Saturday
 * (Sat=1 … Fri=64), and the group statement already runs Saturday to Friday.
 */
final class SettlementWeek
{
    public const string TIMEZONE = 'Africa/Cairo';

    /**
     * The Saturday that starts the week `$at` falls in, at 00:00 Cairo.
     */
    public static function startOf(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)
            ->setTimezone(self::TIMEZONE)
            ->startOfWeek(CarbonImmutable::SATURDAY);
    }

    /**
     * The week `$at` falls in, as its Saturday (`Y-m-d`).
     */
    public static function weekOf(CarbonInterface $at): string
    {
        return self::startOf($at)->toDateString();
    }

    public static function current(): string
    {
        return self::weekOf(now());
    }

    /**
     * The week `$weeks` weeks after (positive) or before (negative) the given one.
     */
    public static function shift(string $weekOf, int $weeks): string
    {
        return CarbonImmutable::parse($weekOf, self::TIMEZONE)->addWeeks($weeks)->toDateString();
    }

    /**
     * When a week's debt is due: its Friday, 23:59 Cairo, as an absolute moment.
     *
     * Overdue means the week has ENDED — the first second of the next Saturday — so a transfer
     * made at 23:59:30 on Friday is still on time.
     */
    public static function dueAt(string $weekOf): CarbonImmutable
    {
        return CarbonImmutable::parse($weekOf, self::TIMEZONE)->addDays(6)->setTime(23, 59);
    }
}
