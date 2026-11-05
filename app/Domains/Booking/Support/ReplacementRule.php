<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\ScheduleLock;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Support\TripSettings;

final class ReplacementRule
{
    public static function assertChangesNobody(Booking $booking, SeatRequest $request, ScheduledTrip $trip): void
    {
        if (! ScheduleLock::isLocked($trip)) {
            return;
        }

        $radius = TripSettings::arrivalRadiusMeters();

        $samePickup = self::metres((float) $booking->pickup_lat, (float) $booking->pickup_lng, (float) $request->requested_pickup_lat, (float) $request->requested_pickup_lng) <= $radius;
        $sameDropoff = self::metres((float) $booking->dropoff_lat, (float) $booking->dropoff_lng, (float) $request->requested_dropoff_lat, (float) $request->requested_dropoff_lng) <= $radius;

        if ($samePickup && $sameDropoff) {
            return;
        }

        throw DomainException::of(ErrorCode::ReplacementChangesOthers, fields: [
            'lockedFrom' => [(string) ScheduleLock::lockedFrom($trip)?->toIso8601String()],
        ]);
    }

    private static function metres(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $dLat = deg2rad($latB - $latA);
        $dLng = deg2rad($lngB - $lngA);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLng / 2) ** 2;

        return 12_742_000 * asin(min(1.0, sqrt($h)));
    }
}
