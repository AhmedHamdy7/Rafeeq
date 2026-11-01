<?php

namespace App\Domains\Commute\Support;

use App\Domains\Driver\Enums\VehicleTier;

/**
 * One automatic seat price and every number it came from (P-1, P-2, P-5; PAY-42, PAY-43, PAY-48).
 *
 *     base       = route km × km rate                         (the driver's take for the whole car)
 *     share      = base ÷ max(approved seats, min divisor)    never rounded to the step
 *     commission = max(rate × share, min commission)          added on top, never taken out
 *     seat price = share + commission, to the nearest step; exactly halfway rounds DOWN, but if
 *                  rounding down would leave RAFEEQ under the minimum it rounds UP instead
 *
 * Worked examples at the beta values (D-0026, all in the tests):
 *
 * | Car and trip              | Share  | Commission | Before rounding | Seat |
 * |---------------------------|--------|------------|-----------------|------|
 * | 3 seats, 40 km Standard   | 100    | 10         | 110             | 110  |
 * | 4 seats, 40 km Standard   | 75     | 7.5 → 8    | 83              | 85   |
 * | 3 seats, 8 km Standard    | 20     | 2 → 8      | 28              | 30   |
 * | share 79                  | 79     | 7.9 → 8    | 87              | 90   |
 * | share 125                 | 125    | 12.5       | 137.5           | 135  |
 * | 3 seats, 40 km Comfort    | 133.33 | 13.33      | 146.67          | 145  |
 *
 * 🔴 The arithmetic runs in **milli-piastres** (1/1000 of a piastre), in integers. Floats would
 * decide "exactly halfway" by accident — 137.5 can come out as 137.49999 — and the halfway rule is
 * the one place the formula has to be exact. A milli-piastre is fine enough that the only rounding
 * left is the final step to a whole piastre, below anything a person can pay.
 *
 * 🔴 What is stored: the seat price, the share and the commission in whole **piastres**, with
 * `commission = seat − share`. The share is rounded to the nearest piastre (never to the 5-EGP step,
 * which is what PAY-43 forbids), and any sub-piastre remainder lands in the commission — RAFEEQ's,
 * like every other rounding difference.
 */
final readonly class SeatPriceQuote
{
    private const int MILLI = 1000;

    private function __construct(
        public int $seatPricePiastres,
        public int $driverSharePiastres,
        public int $commissionPiastres,
        public int $routeDistanceMeters,
        public VehicleTier $tier,
        public int $kmRatePiastres,
        /** What the admin approved; null on a car nobody has reviewed. */
        public ?int $approvedSeats,
        /** What the share was actually divided by: the approved seats, but never under the minimum. */
        public int $seatDivisor,
        public int $commissionRatePercent,
        public int $minCommissionPiastres,
        public int $roundingStepPiastres,
    ) {}

    public static function compute(
        int $routeDistanceMeters,
        VehicleTier $tier,
        int $kmRatePiastres,
        ?int $approvedSeats,
        int $minSeatDivisor,
        int $commissionRatePercent,
        int $minCommissionPiastres,
        int $roundingStepPiastres,
    ): self {
        $divisor = max($approvedSeats ?? 0, $minSeatDivisor, 1);

        // metres × piastres-per-km = piastres × 1/1000, i.e. milli-piastres, with no division yet.
        $baseMilli = max(0, $routeDistanceMeters) * max(0, $kmRatePiastres);

        $shareMilli = intdiv($baseMilli, $divisor);

        $seatPiastres = self::seatPriceForShare(
            $shareMilli,
            $commissionRatePercent,
            $minCommissionPiastres,
            $roundingStepPiastres,
        );

        // Half-up to the piastre. Never above the seat price: the seat is a whole number of
        // piastres at least as large as the exact share, so rounding the share cannot pass it.
        $sharePiastres = intdiv($shareMilli + intdiv(self::MILLI, 2), self::MILLI);

        return new self(
            seatPricePiastres: $seatPiastres,
            driverSharePiastres: $sharePiastres,
            commissionPiastres: $seatPiastres - $sharePiastres,
            routeDistanceMeters: max(0, $routeDistanceMeters),
            tier: $tier,
            kmRatePiastres: $kmRatePiastres,
            approvedSeats: $approvedSeats,
            seatDivisor: $divisor,
            commissionRatePercent: $commissionRatePercent,
            minCommissionPiastres: $minCommissionPiastres,
            roundingStepPiastres: $roundingStepPiastres,
        );
    }

    /**
     * Steps 3 and 4 alone: the seat price for an exact share, in piastres.
     *
     * Public because PAY-43's own examples start from a share ("share 79 → 90"), not from a route,
     * and testing them through a made-up route length would test the made-up length.
     *
     * @param  int  $shareMillipiastres  the driver's exact share per seat, in 1/1000 piastre
     */
    public static function seatPriceForShare(
        int $shareMillipiastres,
        int $commissionRatePercent,
        int $minCommissionPiastres,
        int $roundingStepPiastres,
    ): int {
        $share = max(0, $shareMillipiastres);
        $minimum = max(0, $minCommissionPiastres) * self::MILLI;
        $step = max(1, $roundingStepPiastres) * self::MILLI;

        $commission = max(intdiv($share * max(0, $commissionRatePercent), 100), $minimum);

        $exact = $share + $commission;

        $below = intdiv($exact, $step) * $step;
        $remainder = $exact - $below;

        // Nearest step; a remainder of exactly half is "halfway" and rounds down (PAY-43).
        $seat = $remainder * 2 > $step ? $below + $step : $below;

        /*
         * …unless that leaves RAFEEQ under its minimum, in which case up (PAY-43, D-0026: "share 79
         * + 8 = 87 → 90, not 85"). A loop rather than one step only so that a minimum set larger
         * than the step still holds; at the shipped values it runs at most once.
         */
        while ($seat - $share < $minimum) {
            $seat += $step;
        }

        return intdiv($seat, self::MILLI);
    }

    /**
     * The columns a generated trip day freezes (PAY-44). The keys are the agreed snapshot names
     * on `scheduled_trips`.
     *
     * @return array<string, int|string|null>
     */
    public function toTripColumns(): array
    {
        return [
            'seat_price_piastres' => $this->seatPricePiastres,
            'driver_share_piastres' => $this->driverSharePiastres,
            'commission_piastres' => $this->commissionPiastres,
            'pricing_route_distance_meters' => $this->routeDistanceMeters,
            'pricing_vehicle_tier' => $this->tier->value,
            'pricing_km_rate_piastres' => $this->kmRatePiastres,
            'pricing_approved_seats' => $this->approvedSeats,
            'pricing_seat_divisor' => $this->seatDivisor,
            'pricing_commission_rate_percent' => $this->commissionRatePercent,
            'pricing_min_commission_piastres' => $this->minCommissionPiastres,
            'pricing_rounding_step_piastres' => $this->roundingStepPiastres,
        ];
    }
}
