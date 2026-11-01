<?php

namespace App\Domains\Payment\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Payment\Models\FeeAppeal;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\MemberWarning;

/**
 * Whether somebody has a dispute still open — the one thing that always stops a debt suspension
 * (PAY-19: "for drivers and passengers, suspension for debt is always the last step, and never
 * while a dispute is open").
 *
 * Each counts until staff decide it: an appeal against a fee (SAFE-11 — a passenger's on its
 * warning, a driver's on the fee line), and an attendance dispute ("I was not in that car") on a
 * booking where she is either the passenger or the driver.
 *
 * Payment disputes (PAY-30 → PAY-32) are reports in review; when their outcomes are built they are
 * added here too.
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

        // A driver's appeal against one of his fees.
        $appeal = $appeal || FeeAppeal::query()->where('user_id', $userId)->whereNull('resolution')->exists();

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
