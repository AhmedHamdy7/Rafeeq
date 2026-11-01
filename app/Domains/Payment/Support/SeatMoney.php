<?php

namespace App\Domains\Payment\Support;

use App\Domains\Booking\Models\Booking;

/**
 * What one booking leaves the driver owing RAFEEQ when the passenger is dropped off (PAY-07), and
 * what one seat costs.
 *
 * ⚠️ SEAM for the automatic price (Feature 03 backend file P-1 → P-5, workstream W1). Until the
 * per-day price snapshot exists, the commission is the booking's own frozen
 * `platform_fee_snapshot_piastres` — which is what the old 3 % fee was, and where the new
 * commission (10 %, at least 8 EGP, PAY-41/PAY-48) lands once the price engine writes it. Either
 * way it is read from the booking's snapshot, never recomputed from today's settings: a settings
 * change must never alter a ride already agreed (PAY-44).
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
