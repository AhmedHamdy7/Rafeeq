<?php

namespace App\Domains\Booking\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * Splitting a seat's price between the driver and the platform.
 *
 * The invariant is absolute and the database enforces it too:
 * `price = platform_fee + driver_amount`, exactly, with no rounding left over.
 * The Bible lists it as a non-negotiable test — "keeps driver_amount +
 * platform_fee exactly equal to amount" — because a single piastre lost to
 * rounding on every booking becomes a ledger that never reconciles.
 *
 * So the fee is rounded and the driver's share is whatever remains. Computing
 * both independently is how the two stop adding up.
 *
 * Every booking freezes its own split at approval (pitfall #42): changing the
 * percentage later must not move money on a booking that already exists.
 */
final class FeeSplit
{
    /**
     * @return array{price: int, platformFee: int, driverAmount: int}
     */
    public static function forSeats(int $pricePerSeatPiastres, int $seats): array
    {
        $price = $pricePerSeatPiastres * $seats;

        $percent = (float) PlatformSetting::value(
            'booking.platform_fee_percent',
            config('rafeeq.booking.platform_fee_percent'),
        );

        $platformFee = (int) round($price * $percent / 100);

        return [
            'price' => $price,
            'platformFee' => $platformFee,
            // Subtraction, not a second percentage: this is what guarantees the
            // two halves sum to the whole for every possible price.
            'driverAmount' => $price - $platformFee,
        ];
    }
}
