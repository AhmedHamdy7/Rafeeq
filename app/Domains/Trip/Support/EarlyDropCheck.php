<?php

namespace App\Domains\Trip\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Payment\Support\FeeSettings;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Actions\RecordTripLocationAction;
use App\Domains\Trip\Models\TripSession;

/**
 * "Did you ask to get off here?" (TRIP-20, PAY-28; Feature 03 backend file F-9).
 *
 * When the DRIVER sets her down — not her own "Drop me here" — more than 1 km before her agreed
 * drop-off, measured from where the car is at that moment, the booking is marked and she is asked.
 * Her answer is `POST /bookings/{booking}/early-drop`.
 *
 * Unknown position → nothing is asked: a check with nothing to stand on would accuse a driver on a
 * guess. No answer is open question Q-066 and changes nothing yet.
 */
final readonly class EarlyDropCheck
{
    public function __construct(
        private RecordTripLocationAction $locations,
        private GeoQueryEngine $geo,
    ) {}

    public function afterDropOff(TripSession $session, Booking $booking): void
    {
        $dropoff = $booking->dropoff_point;
        $position = $this->locations->current($session);

        if ($dropoff === null || $position === null) {
            return;
        }

        $short = $this->geo->distanceMeters(new Coordinate((float) $position->lat, (float) $position->lng), $dropoff)->metres;

        if ($short <= FeeSettings::earlyDropMeters()) {
            return;
        }

        $booking->forceFill([
            'early_drop_asked_at' => now(),
            'early_drop_distance_meters' => $short,
        ])->save();

        if (($passenger = User::query()->find($booking->passenger_user_id)) !== null) {
            Notifier::send($passenger, NotificationType::EarlyDropCheck, params: [
                'distance' => number_format($short / 1000, 1),
            ], data: ['bookingId' => (string) $booking->getKey()]);
        }
    }
}
