<?php

namespace App\Domains\Commute\Support;

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Identity\Support\ProfileSettings;

/**
 * The numbers behind "the fair suggested price" (ERD §23.3).
 *
 * Same contract as {@see ProfileSettings}: `platform_settings`
 * wins and `config/rafeeq.php` holds the shipped default, because every one of these is a
 * policy figure that moves without a deploy (standard #11).
 *
 * 🔴 `cost_per_km_piastres` in particular. It tracks the fuel price, so it changes several
 * times a year and on somebody else's schedule — and the ERD notes that the admin
 * dashboard has a "fuel index update" broadcast that tells every driver when it moves.
 * A suggestion computed from a hard-coded fuel cost would quietly start advising people
 * to undercharge the week petrol goes up.
 */
final class PricingSettings
{
    /** What a kilometre costs to drive, in piastres. Tracks the fuel price. */
    public static function costPerKmPiastres(): int
    {
        return (int) self::get('pricing.cost_per_km_piastres');
    }

    /**
     * How many passengers a commute is assumed to carry when splitting the cost.
     *
     * An assumption on purpose, and it has to be: the suggestion is made while the driver
     * is still filling in the form, before anybody has booked. Computing it from the seats
     * actually sold would mean the advice changed every time somebody joined — and the
     * price is frozen per booking anyway (decision: a fixed price per seat, ERD §23.2).
     */
    public static function assumedOccupancy(): int
    {
        return max(1, (int) self::get('pricing.assumed_occupancy'));
    }

    /** Suggestions are rounded to this, so drivers are advised round numbers. */
    public static function roundingStepPiastres(): int
    {
        return max(1, (int) self::get('pricing.rounding_step_piastres'));
    }

    public static function minPiastres(): int
    {
        return (int) self::get('commute.min_price_piastres');
    }

    /**
     * The ceiling, which is a legal boundary rather than a preference: a commute is shared
     * cost, and a price far above the cost of driving makes the platform an unlicensed
     * taxi service.
     */
    public static function maxPiastres(): int
    {
        return (int) self::get('commute.max_price_piastres');
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
