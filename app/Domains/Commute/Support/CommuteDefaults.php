<?php

namespace App\Domains\Commute\Support;

use Carbon\CarbonImmutable;

final class CommuteDefaults
{
    private const string TIMEZONE = 'Africa/Cairo';

    public static function startDate(): string
    {
        return CarbonImmutable::tomorrow(self::TIMEZONE)->toDateString();
    }

    public static function endDate(CarbonImmutable $start): string
    {
        return $start->addMonths(self::maxScheduleMonths())->toDateString();
    }

    public static function maxScheduleMonths(): int
    {
        return (int) config('rafeeq.commute.max_schedule_months');
    }
}
