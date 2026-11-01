<?php

namespace App\Domains\Payment\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * The debt and settlement numbers staff may change at runtime (ADM-06). `platform_settings` wins,
 * `config/rafeeq.php` holds the shipped default (standard #11). Each key is in `SettingsCatalogue`.
 */
final class DebtSettings
{
    /** PAY-13: +10 % once on a week's debt paid after its Friday. */
    public static function lateFeePercent(): int
    {
        return max(0, (int) self::get('debt.late_fee_percent'));
    }

    /** PAY-45: how many Friday deadlines a week's debt may miss before the driver is suspended. */
    public static function weeksBeforeSuspension(): int
    {
        return max(2, (int) self::get('debt.weeks_before_suspension'));
    }

    /** PAY-17: days a passenger's fee may stay unpaid without her riding. */
    public static function passengerSuspensionDays(): int
    {
        return max(1, (int) self::get('debt.passenger_suspension_days'));
    }

    /** PAY-17: passenger debt OVER this suspends her. */
    public static function passengerSuspensionLimitPiastres(): int
    {
        return max(0, (int) self::get('debt.passenger_suspension_limit_piastres'));
    }

    /** PAY-34: a coupon is this share of the fee actually collected. */
    public static function couponPercent(): int
    {
        return max(0, min(100, (int) self::get('debt.coupon_percent')));
    }

    /** The Cairo hour of the day's debt reminder. */
    public static function reminderHour(): int
    {
        return max(0, min(23, (int) self::get('debt.reminder_hour')));
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
