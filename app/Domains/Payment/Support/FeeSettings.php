<?php

namespace App\Domains\Payment\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * The fee numbers, read live from platform settings with the config as the default (PAY-20 →
 * PAY-29). Percentages are of the seat price; see config `rafeeq.fees`.
 */
final class FeeSettings
{
    public static function counterResetDays(): int
    {
        return max(1, (int) self::get('fees.counter_reset_days'));
    }

    /**
     * PAY-20. `$step` is the rung, from 2 (the first fee) to 5 (the cap).
     */
    public static function noShowPercent(int $step): int
    {
        return self::percent(match (min(5, $step)) {
            2 => self::get('fees.no_show_step_2_percent'),
            3 => self::get('fees.no_show_step_3_percent'),
            4 => self::get('fees.no_show_step_4_percent'),
            5 => self::get('fees.no_show_step_5_percent'),
            default => 0,
        });
    }

    public static function freeCancelPickupMovedMinutes(): int
    {
        return max(0, (int) self::get('fees.free_cancel_pickup_moved_minutes'));
    }

    public static function lateCancelHours(): int
    {
        return max(1, (int) self::get('fees.late_cancel_hours'));
    }

    public static function lateCancelSharePercent(): int
    {
        return self::percent(self::get('fees.late_cancel_share_percent'));
    }

    /**
     * PAY-22. `$step` from 3 (the first fee) to 5 (the cap).
     */
    public static function latenessPercent(int $step): int
    {
        return self::percent(match (min(5, $step)) {
            3 => self::get('fees.lateness_step_3_percent'),
            4 => self::get('fees.lateness_step_4_percent'),
            5 => self::get('fees.lateness_step_5_percent'),
            default => 0,
        });
    }

    public static function appealHours(): int
    {
        return max(1, (int) self::get('fees.appeal_hours'));
    }

    public static function driverCancelFreeHours(): int
    {
        return max(0, (int) self::get('fees.driver_cancel_free_hours'));
    }

    public static function driverCancelShortHours(): int
    {
        return max(0, (int) self::get('fees.driver_cancel_short_hours'));
    }

    public static function driverCancelMidPercent(): int
    {
        return self::percent(self::get('fees.driver_cancel_mid_percent'));
    }

    public static function driverCancelShortPercent(): int
    {
        return self::percent(self::get('fees.driver_cancel_short_percent'));
    }

    public static function driverActiveCancelPercent(): int
    {
        return self::percent(self::get('fees.driver_active_cancel_percent'));
    }

    public static function proRataFreePercent(): int
    {
        return self::percent(self::get('fees.pro_rata_free_percent'));
    }

    public static function driverNoShowPercent(): int
    {
        return self::percent(self::get('fees.driver_no_show_percent'));
    }

    public static function driverLatePercent(): int
    {
        return self::percent(self::get('fees.driver_late_percent'));
    }

    public static function earlyDropMeters(): int
    {
        return max(100, (int) self::get('fees.early_drop_meters'));
    }

    public static function paymentLiePassengerPercent(): int
    {
        return self::percent(self::get('fees.payment_lie_passenger_percent'));
    }

    public static function paymentLieDriverPercent(): int
    {
        return self::percent(self::get('fees.payment_lie_driver_percent'));
    }

    public static function womenOnlyMisusePercent(): int
    {
        return self::percent(self::get('fees.women_only_misuse_percent'));
    }

    private static function percent(mixed $value): int
    {
        return max(0, min(200, (int) $value));
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
