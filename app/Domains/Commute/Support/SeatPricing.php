<?php

namespace App\Domains\Commute\Support;

use App\Domains\Driver\Models\Vehicle;

/**
 * The automatic seat price for a route in a car, at TODAY's settings (P-1, P-2; PAY-42).
 *
 * The one place that reads the pricing settings and the car's review together. Call it when a
 * day is generated or a price is previewed — never to describe a day that already exists, which
 * carries its own frozen quote (PAY-44).
 */
final class SeatPricing
{
    public static function quote(int $routeDistanceMeters, Vehicle $vehicle): SeatPriceQuote
    {
        $tier = $vehicle->tierOrDefault();

        return SeatPriceQuote::compute(
            routeDistanceMeters: $routeDistanceMeters,
            tier: $tier,
            kmRatePiastres: PricingSettings::kmRatePiastres($tier),
            approvedSeats: $vehicle->approved_seats,
            minSeatDivisor: PricingSettings::minSeatDivisor(),
            commissionRatePercent: PricingSettings::commissionRatePercent(),
            minCommissionPiastres: PricingSettings::minCommissionPiastres(),
            roundingStepPiastres: PricingSettings::roundingStepPiastres(),
        );
    }

    public const int EXAMPLE_SHARE_PIASTRES = 11_500;

    /**
     * @return array{share: int, commission: int, seat: int}
     */
    public static function exampleAtRate(int $commissionRatePercent): array
    {
        $seat = SeatPriceQuote::seatPriceForShare(
            self::EXAMPLE_SHARE_PIASTRES * 1000,
            $commissionRatePercent,
            PricingSettings::minCommissionPiastres(),
            PricingSettings::roundingStepPiastres(),
        );

        return [
            'share' => self::EXAMPLE_SHARE_PIASTRES,
            'commission' => $seat - self::EXAMPLE_SHARE_PIASTRES,
            'seat' => $seat,
        ];
    }
}
