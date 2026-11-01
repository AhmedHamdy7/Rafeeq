<?php

namespace App\Domains\Commute\Support;

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Identity\Support\ProfileSettings;

/**
 * The numbers behind the automatic seat price (PAY-41 → PAY-43, PAY-48; ADM-06).
 *
 * Same contract as {@see ProfileSettings}: `platform_settings` wins and `config/rafeeq.php` holds
 * the shipped default, because every one of these is a business decision the partners move without
 * a deploy — the commission goes from 10% to 15% at the public launch (PAY-41), and the km rates
 * track the fuel price on somebody else's schedule.
 *
 * Read ONLY when a trip day is priced. The day then carries the values it used (PAY-44), so
 * nothing downstream should ever read these to describe a day that already exists.
 */
final class PricingSettings
{
    /** What a kilometre of the whole route earns the driver, by the car's tier (PAY-42). */
    public static function kmRatePiastres(VehicleTier $tier): int
    {
        return match ($tier) {
            VehicleTier::Standard => max(0, (int) self::get('pricing.km_rate_standard_piastres')),
            VehicleTier::Comfort => max(0, (int) self::get('pricing.km_rate_comfort_piastres')),
        };
    }

    /** RAFEEQ's commission, in whole percents of the driver's share (PAY-41). */
    public static function commissionRatePercent(): int
    {
        return max(0, (int) self::get('pricing.commission_rate_percent'));
    }

    /** The least RAFEEQ takes on one seat (PAY-48). */
    public static function minCommissionPiastres(): int
    {
        return max(0, (int) self::get('pricing.min_commission_piastres'));
    }

    /** Seat prices land on multiples of this (PAY-43). */
    public static function roundingStepPiastres(): int
    {
        return max(1, (int) self::get('pricing.rounding_step_piastres'));
    }

    /** The share is never divided by fewer seats than this, whatever the car (D-0025). */
    public static function minSeatDivisor(): int
    {
        return max(1, (int) self::get('pricing.min_seat_divisor'));
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
