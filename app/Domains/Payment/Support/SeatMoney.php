<?php

namespace App\Domains\Payment\Support;

use App\Domains\Booking\Models\Booking;

/**
 * What one booking leaves the driver owing RAFEEQ when the passenger is dropped off (PAY-07), and
 * what one seat costs.
 *
 * The commission is the booking's own frozen `platform_fee_snapshot_piastres`, which the booking
 * copies from its trip day's automatic-price snapshot (PAY-44, PAY-48) times the seats. Read from
 * the snapshot, never recomputed from today's settings: a settings change must never alter a ride
 * already agreed.
 *
 * One place, so the price work changes one line rather than every settlement path.
 */
final class SeatMoney
{
    public static function commissionPiastres(Booking $booking): int
    {
        return max(0, (int) $booking->platform_fee_snapshot_piastres);
    }

    /**
     * The price of one seat — the cap on a coupon (PAY-34).
     */
    public static function seatPricePiastres(Booking $booking): int
    {
        return intdiv(max(0, (int) $booking->price_snapshot_piastres), max(1, (int) $booking->seats_reserved));
    }
}
