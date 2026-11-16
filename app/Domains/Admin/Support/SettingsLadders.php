<?php

namespace App\Domains\Admin\Support;

/**
 * The fee ladders (F02): settings that only make sense together, edited as one.
 *
 * Each rung is still its own setting — read by FeeSettings, bounded by SettingsCatalogue, audited
 * one row each — but a ladder is changed in one go and must never go down: a third no-show
 * cheaper than the second would reward the habit the ladder exists to discourage.
 *
 * The preview prices every rung on one seat (`EXAMPLE_SEAT_PIASTRES`, EGP 110, the design's
 * example), with the same arithmetic the fees use: percent of the frozen seat price, in basis
 * points, rounded down to the piastre.
 */
final class SettingsLadders
{
    public const int EXAMPLE_SEAT_PIASTRES = 11_000;

    /**
     * Ladder => rung label => setting key, lowest rung first.
     *
     * @var array<string, array<string, string>>
     */
    public const array LADDERS = [
        // PAY-20: the first no-show is a warning; these are the 2nd to the 5th and after.
        'no_show' => [
            '2' => 'fees.no_show_step_2_percent',
            '3' => 'fees.no_show_step_3_percent',
            '4' => 'fees.no_show_step_4_percent',
            '5' => 'fees.no_show_step_5_percent',
        ],
        // PAY-22: the first two late boardings are warnings; these are the 3rd to the 5th and after.
        'lateness' => [
            '3' => 'fees.lateness_step_3_percent',
            '4' => 'fees.lateness_step_4_percent',
            '5' => 'fees.lateness_step_5_percent',
        ],
        // PAY-23: the driver cancelling with less notice pays more.
        'driver_cancel' => [
            'mid' => 'fees.driver_cancel_mid_percent',
            'short' => 'fees.driver_cancel_short_percent',
        ],
    ];

    /** @return array<string, string>|null */
    public static function ladder(string $name): ?array
    {
        return self::LADDERS[$name] ?? null;
    }

    /** What a rung at this percentage costs on the example seat, in piastres. */
    public static function example(int $percent): int
    {
        return intdiv(self::EXAMPLE_SEAT_PIASTRES * $percent * 100, 10_000);
    }
}
