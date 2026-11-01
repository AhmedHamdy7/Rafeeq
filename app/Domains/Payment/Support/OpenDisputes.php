<?php

namespace App\Domains\Payment\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Models\Attendance;

/**
 * Whether somebody has a dispute still open — the one thing that always stops a debt suspension
 * (PAY-19: "for drivers and passengers, suspension for debt is always the last step, and never
 * while a dispute is open").
 *
 * Today the only dispute the platform records is an attendance dispute ("I was not in that car")
 * that staff have not decided yet, on a booking where she is either the passenger or the driver.
 *
 * ⚠️ SEAM for the payment disputes of PAY-30 → PAY-32 (Feature 03 backend file F-11, workstream W3):
 * when those exist, they are added here, and every debt suspension path picks them up.
 */
final class OpenDisputes
{
    public static function existFor(string $userId): bool
    {
        return Attendance::query()
            ->whereNotNull('disputed_at')
            ->whereNull('dispute_resolution')
            ->whereIn('booking_id', Booking::query()
                ->where(fn ($query) => $query
                    ->where('passenger_user_id', $userId)
                    ->orWhere('driver_profile_id', $userId))
                ->select('id'))
            ->exists();
    }
}
