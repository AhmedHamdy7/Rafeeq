<?php

namespace App\Domains\Payment\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\MemberWarning;

/**
 * Whether somebody has a dispute still open — the one thing that always stops a debt suspension
 * (PAY-19: "for drivers and passengers, suspension for debt is always the last step, and never
 * while a dispute is open").
 *
 * Two kinds are recorded, both counted until staff decide them: her appeal against a fee or a
 * warning (SAFE-11), and an attendance dispute ("I was not in that car") on a booking where she is
 * either the passenger or the driver.
 *
 * ⚠️ SEAM for the payment disputes of PAY-30 → PAY-32 (Feature 03 backend file F-11, workstream W3):
 * when those exist, they are added here, and every debt suspension path picks them up.
 */
final class OpenDisputes
{
    public static function existFor(string $userId): bool
    {
        // An appeal of hers against a fee or a warning that staff have not decided (SAFE-11).
        $appeal = MemberWarning::query()
            ->where('user_id', $userId)
            ->whereNotNull('disputed_at')
            ->whereNull('dispute_resolution')
            ->exists();

        return $appeal || Attendance::query()
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
