<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Payment\Support\FeeSettings;
use Carbon\CarbonImmutable;

final class FreeCancel
{
    public static function pickupMovedEarlier(Booking $booking): bool
    {
        if ($booking->agreed_pickup_at === null) {
            return false;
        }

        $booking->loadMissing('scheduledTrip.commuteOffer.locations');

        if ($booking->scheduledTrip?->status !== ScheduledTripStatus::Scheduled) {
            return false;
        }

        $current = PickupEta::forBooking(app(GeoQueryEngine::class), $booking);

        if ($current === null) {
            return false;
        }

        $movedSeconds = CarbonImmutable::instance($booking->agreed_pickup_at)->getTimestamp() - $current->getTimestamp();

        return $movedSeconds > FeeSettings::freeCancelPickupMovedMinutes() * 60;
    }

    public static function rememberAgreedPickup(Booking $booking): void
    {
        $booking->loadMissing('scheduledTrip.commuteOffer.locations');

        $booking->forceFill([
            'agreed_pickup_at' => PickupEta::forBooking(app(GeoQueryEngine::class), $booking),
        ])->save();
    }
}
