<?php

namespace App\Domains\Commute\Support;

use Carbon\CarbonImmutable;

/**
 * 🔴 Bible pitfall #6, the single most dangerous bug in the project: Egypt
 * has observed real DST by law since 2023 (last Friday of April → last
 * Thursday of October). `commute_schedules` stores the LOCAL wall-clock time
 * + timezone; the UTC instant is computed here, once, at generation time —
 * never stored as a fixed UTC offset ahead of time.
 */
final class DepartureTimeCalculator
{
    public static function toUtc(string $tripDate, string $localTime, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse("{$tripDate} {$localTime}", $timezone)->utc();
    }
}
