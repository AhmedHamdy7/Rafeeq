<?php

namespace App\Domains\Commute\Support;

use App\Domains\Shared\ValueObjects\Distance;

/**
 * "80 ج.م — المقترح العادل" on the publish screen, and where the number comes from.
 *
 * ERD §23.3 sets out the arithmetic, and this is that formula and nothing else:
 *
 *     suggested per seat = (distance in km × cost per km) ÷ assumed occupancy
 *                          rounded to the nearest step, clamped to the legal bounds
 *
 * 🔴 Why this exists at all, rather than leaving the driver to pick a number: a commute is
 * shared COST, not a fare. A driver with no reference point either undercharges and quietly
 * subsidises strangers, or overcharges and turns their own car into an unlicensed taxi —
 * and only one of those two is their problem to notice. The suggestion is the platform
 * saying what the journey actually costs to drive.
 *
 * It is advice, never a rule. The driver may set anything between the bounds, and the
 * screen shows the suggestion beside the slider rather than moving it.
 */
final readonly class FairPriceSuggestion
{
    private function __construct(
        /** What the journey costs to drive, split by the assumed occupancy. */
        public int $suggestedPiastres,
        /** The lowest a seat may be priced. */
        public int $minPiastres,
        /** The ceiling — a legal boundary, not a preference. See PricingSettings. */
        public int $maxPiastres,
        /** What the whole run costs to drive, before splitting. Screen 24 shows it. */
        public int $runCostPiastres,
        /** How many passengers the split assumed, so the screen can say so. */
        public int $assumedOccupancy,
    ) {}

    public static function forDistance(Distance $distance): self
    {
        $costPerKm = PricingSettings::costPerKmPiastres();
        $occupancy = PricingSettings::assumedOccupancy();
        $step = PricingSettings::roundingStepPiastres();

        $min = PricingSettings::minPiastres();
        $max = PricingSettings::maxPiastres();

        $runCost = (int) round($distance->kilometres() * $costPerKm);

        $perSeat = $runCost / $occupancy;

        // Rounded to the nearest step so a driver is advised 70 rather than 67.5 — the
        // suggestion is meant to be typed in, and nobody types 67.5.
        $rounded = (int) (round($perSeat / $step) * $step);

        return new self(
            /*
             * Clamped last. A very short journey computes below the floor and a very long
             * one above the ceiling, and in both cases the bound is the honest advice:
             * suggesting something the driver is not allowed to set would be advice that
             * fails validation.
             */
            suggestedPiastres: max($min, min($rounded, $max)),
            minPiastres: $min,
            maxPiastres: $max,
            runCostPiastres: $runCost,
            assumedOccupancy: $occupancy,
        );
    }
}
