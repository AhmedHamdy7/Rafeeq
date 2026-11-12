<?php

namespace App\Domains\Safety\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Safety\Models\LiveShare;
use App\Domains\Trip\Models\TripSession;

final class LiveShares
{
    public static function endForPassenger(string $tripSessionId, string $userId): int
    {
        return LiveShare::query()
            ->where('trip_session_id', $tripSessionId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public static function endForBooking(Booking $booking): int
    {
        $sessionId = TripSession::query()
            ->where('scheduled_trip_id', $booking->scheduled_trip_id)
            ->value('id');

        if ($sessionId === null) {
            return 0;
        }

        return self::endForPassenger((string) $sessionId, (string) $booking->passenger_user_id);
    }

    public static function endForRun(string $tripSessionId): int
    {
        return LiveShare::query()
            ->where('trip_session_id', $tripSessionId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
