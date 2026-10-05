<?php

namespace App\Domains\Notification\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use Carbon\CarbonImmutable;

/**
 * When a booking's conversation is open (Chapter 11 §Messaging Rules).
 *
 * "Chat opens after booking confirmation. Chat closes after the trip plus a configurable grace
 * period." Read literally, a recurring member — booked a month ahead, one booking per day —
 * would have thirty channels open at once, which is exactly the "long-term messaging" the same
 * chapter says this is not. So the window is the JOURNEY's, not the booking's:
 *
 *   opens   departure − `chat.opens_hours_before` (24h)
 *   closes  end of the run + `chat.grace_minutes` (2h), where the end is when the driver
 *           completed it, or — if nobody ever did — departure + `chat.max_hours_after_departure`
 *
 * And a cancelled booking has no conversation at all: the two people are not travelling
 * together any more.
 *
 * Computed, never stored. `conversations.closed_at` records when a conversation was SEEN to be
 * closed; whether it is open is always this calculation, so changing a setting moves every
 * window at once instead of leaving rows with yesterday's answer.
 */
final class ChatWindow
{
    public static function opensAt(Booking $booking): CarbonImmutable
    {
        return CarbonImmutable::parse($booking->scheduledTrip->departure_at)
            ->subHours(ChatSettings::opensHoursBefore());
    }

    public static function closesAt(Booking $booking): CarbonImmutable
    {
        $trip = $booking->scheduledTrip;
        $completedAt = $trip->tripSession?->completed_at;

        $end = $completedAt !== null
            ? CarbonImmutable::parse($completedAt)
            : CarbonImmutable::parse($trip->departure_at)->addHours(ChatSettings::maxHoursAfterDeparture());

        return $end->addMinutes(ChatSettings::graceMinutes());
    }

    public static function isOpen(Booking $booking): bool
    {
        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Completed], true)) {
            return false;
        }

        $now = CarbonImmutable::now();

        return $now->greaterThanOrEqualTo(self::opensAt($booking)) && $now->lessThan(self::closesAt($booking));
    }
}
