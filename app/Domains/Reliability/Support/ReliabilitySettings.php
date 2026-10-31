<?php

namespace App\Domains\Reliability\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * The numbers behind the Reliability Score (Feature 03 files X-3, decisions D3/D4).
 *
 * Same contract as the other settings readers: `platform_settings` wins, `config/rafeeq.php` holds
 * the shipped default (standard #11). Each key is written out literally, one method per weight,
 * because `SettingsTest` finds the settings the code reads by scanning for `self::get('…')` — a key
 * assembled from a variable would be a number nobody could see on the Settings page.
 *
 * ⚠️ The score gates nothing (D4). These change how a member's own number reads and what the
 * monthly review sees, never whether anybody may book or drive.
 */
final class ReliabilitySettings
{
    /** How far back the score looks, in days. */
    public static function windowDays(): int
    {
        return max(1, (int) self::get('reliability.window_days'));
    }

    /** Fewer hours' notice than this is a short-notice cancellation ("< 10h"). */
    public static function lateCancellationHours(): int
    {
        return max(0, (int) self::get('reliability.late_cancellation_hours'));
    }

    public static function driverNoShow(): int
    {
        return self::weight(self::get('reliability.weights.driver_no_show'));
    }

    public static function passengerNoShow(): int
    {
        return self::weight(self::get('reliability.weights.passenger_no_show'));
    }

    public static function driverCancelShortNotice(): int
    {
        return self::weight(self::get('reliability.weights.driver_cancel_short_notice'));
    }

    public static function driverCancelMidNotice(): int
    {
        return self::weight(self::get('reliability.weights.driver_cancel_mid_notice'));
    }

    public static function driverCancelLongNotice(): int
    {
        return self::weight(self::get('reliability.weights.driver_cancel_long_notice'));
    }

    public static function passengerCancelShortNotice(): int
    {
        return self::weight(self::get('reliability.weights.passenger_cancel_short_notice'));
    }

    public static function driverLate(): int
    {
        return self::weight(self::get('reliability.weights.driver_late'));
    }

    public static function passengerLate(): int
    {
        return self::weight(self::get('reliability.weights.passenger_late'));
    }

    public static function driverEndedTrip(): int
    {
        return self::weight(self::get('reliability.weights.driver_ended_trip'));
    }

    // Never negative: a negative weight would ADD points, which no event in the table does.
    private static function weight(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
