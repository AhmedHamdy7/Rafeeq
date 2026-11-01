<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Trips tab's two lists, as queries (HISTORY-2): what is behind her, and what is still ahead.
 *
 * 🔴 Both order by the JOURNEY's departure, not by when the booking row was written. A recurring
 * membership writes a month of bookings in one approval, so "newest booking first" puts a seat for
 * next Thursday above the one she rode this morning — which is right for nobody's History.
 *
 * The two lists are disjoint by status, and that is the whole of the split: a finished booking is
 * one in a terminal status, whatever its date. A seat still `CONFIRMED` on a morning that has gone
 * by is in neither — the day's own sweep will settle it into one, and showing it as "upcoming"
 * yesterday would be the one answer that is certainly wrong.
 */
final class PassengerBookingScope
{
    /**
     * @param  Builder<Booking>  $query
     */
    public static function past(Builder $query): void
    {
        $finished = [];

        foreach (BookingStatus::cases() as $status) {
            if ($status->isTerminal()) {
                $finished[] = $status->value;
            }
        }

        self::byJourney($query)
            ->whereIn('bookings.status', $finished)
            ->orderByDesc('scheduled_trips.departure_at')
            // A tiebreak, so paging is stable when two bookings share a departure.
            ->orderByDesc('bookings.id');
    }

    /**
     * Her open seats on journeys that have not finished — the same "current" rule the home
     * screen's next-journey card uses: not yet departed, or departed and still on the road.
     *
     * @param  Builder<Booking>  $query
     */
    public static function upcoming(Builder $query): void
    {
        self::byJourney($query)
            ->whereIn('bookings.status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
            ->where(fn (Builder $inner) => $inner
                ->where('scheduled_trips.departure_at', '>=', now())
                ->orWhereIn('scheduled_trips.status', [
                    ScheduledTripStatus::Preparing->value,
                    ScheduledTripStatus::EnRoute->value,
                    ScheduledTripStatus::InProgress->value,
                ]))
            ->whereNotIn('scheduled_trips.status', [
                ScheduledTripStatus::Completed->value,
                ScheduledTripStatus::Cancelled->value,
            ])
            ->orderBy('scheduled_trips.departure_at')
            ->orderBy('bookings.id');
    }

    /**
     * Joined rather than filtered with `whereHas`: the order is a column on the trip, and an
     * unjoined query cannot sort by it.
     *
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    private static function byJourney(Builder $query): Builder
    {
        return $query
            ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
            ->select('bookings.*');
    }
}
