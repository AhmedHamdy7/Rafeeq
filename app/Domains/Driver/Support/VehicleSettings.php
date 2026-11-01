<?php

namespace App\Domains\Driver\Support;

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Identity\Support\ProfileSettings;

/**
 * Car-review numbers an admin may move (ADM-06), on the same contract as {@see ProfileSettings}.
 */
final class VehicleSettings
{
    /**
     * The fewest approved seats a car may be given at verification (PROF-28: "at least 3").
     *
     * Separate from `pricing.min_seat_divisor` although both ship as 3. One is a rule about which
     * cars may sell seats at all; the other is a rule about how a price is divided. D-0025 happens
     * to make them the same number today, and nothing says they will stay so.
     */
    public static function minApprovedSeats(): int
    {
        return max(1, (int) self::get('driver.min_approved_seats'));
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
