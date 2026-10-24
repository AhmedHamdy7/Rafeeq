<?php

namespace App\Domains\Notification\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
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

    /**
     * A seat request's thread, which has no window at all (Feature 03 v5: chat is "scoped to
     * request/booking/waiting-list").
     *
     * 🔴 **Open for exactly as long as the request is a live question, and not a minute either
     * side.** No hours-before, no grace: the thread exists so the two of them can settle what the
     * request does not say, and that need starts the moment she asks and ends the moment it is
     * answered.
     *
     * So the three live states, and why each one is in:
     *
     *   - `Pending` — the driver is deciding. This is the exchange the whole thing is for.
     *   - `Counteroffered` — the driver proposed another place and is waiting on her. Closing the
     *     thread here would be the worst possible moment: she has a decision to make about a point
     *     she did not choose, and the only person who can explain it is the one who proposed it.
     *   - `Waitlisted` — she is waiting for a seat in this car, and the driver has already approved
     *     her as somebody she would carry. A waiting list is a relationship, not a queue ticket.
     *
     * 🔒 And why a refusal shuts it: a driver who said no has said everything she owes, and a
     * channel that outlived her refusal would be a way to keep talking to somebody who declined to
     * carry you. The same goes for a withdrawn or expired request in the other direction. The
     * history stays readable — what was said is evidence — but nothing more can be sent.
     *
     * ⚠️ An APPROVED request closes its thread too, and does not leave a gap: approval creates a
     * booking, and the booking's own conversation is where the two of them talk about the journey.
     */
    public static function isOpenForRequest(SeatRequest $request): bool
    {
        return in_array($request->status, [
            SeatRequestStatus::Pending,
            SeatRequestStatus::Counteroffered,
            SeatRequestStatus::Waitlisted,
        ], true);
    }
}
