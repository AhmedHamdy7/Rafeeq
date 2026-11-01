<?php

namespace App\Domains\Safety\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;

/**
 * The other person on a booking, by id — returned once the journey has ended, so she can block or
 * report them from the trip summary (Feature 03 files X-4, decision D6; SAFE-15: "from history or
 * the trip summary").
 *
 * 🔒 **Only after the journey, never before.** `PersonSummary` carries no id on purpose: an id is
 * a handle that outlives the trip, and before two people have shared a car there is no reason for
 * either to hold one on the other. Once the journey is over — she rode, she got out early, she was
 * left waiting, or he was recorded absent — Block and Report are exactly what the summary screen is
 * for, and they need it.
 *
 * Only to the two people on the booking: the passenger gets the driver's id, the driver the
 * passenger's. Anybody else — and a booking still pending, confirmed or cancelled — gets null.
 */
final class OtherParty
{
    /**
     * The statuses that mean this booking's journey is over and the two were in it together (or
     * one of them failed the other at the kerb, which is just as blockable).
     */
    private const array ENDED = [
        BookingStatus::Completed,
        BookingStatus::EndedEarly,
        BookingStatus::NoShow,
        BookingStatus::ExitedDueToDriverDelay,
    ];

    public static function idFor(Booking $booking, ?User $viewer): ?string
    {
        if ($viewer === null || ! in_array($booking->status, self::ENDED, true)) {
            return null;
        }

        return match ((string) $viewer->id) {
            (string) $booking->passenger_user_id => (string) $booking->driver_profile_id,
            (string) $booking->driver_profile_id => (string) $booking->passenger_user_id,
            default => null,
        };
    }
}
