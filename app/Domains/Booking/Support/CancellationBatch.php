<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use Illuminate\Database\Eloquent\Builder;

final class CancellationBatch
{
    public const int WINDOW_SECONDS = 120;

    /**
     * @return Builder<Booking>
     */
    public static function of(Booking $booking): Builder
    {
        $query = Booking::query()->whereKey($booking->getKey());

        if ($booking->status !== BookingStatus::CancelledByDriver || $booking->cancelled_at === null) {
            return $query;
        }

        $offerId = $booking->scheduledTrip?->commute_offer_id;

        return Booking::query()
            ->where('bookings.passenger_user_id', $booking->passenger_user_id)
            ->where('bookings.status', BookingStatus::CancelledByDriver->value)
            ->where('bookings.driver_profile_id', $booking->driver_profile_id)
            ->when(
                $booking->cancelled_reason === null,
                fn (Builder $q) => $q->whereNull('bookings.cancelled_reason'),
                fn (Builder $q) => $q->where('bookings.cancelled_reason', $booking->cancelled_reason),
            )
            ->whereBetween('bookings.cancelled_at', [
                $booking->cancelled_at->copy()->subSeconds(self::WINDOW_SECONDS),
                $booking->cancelled_at->copy()->addSeconds(self::WINDOW_SECONDS),
            ])
            ->when($offerId !== null, fn (Builder $q) => $q->whereHas(
                'scheduledTrip',
                fn (Builder $trip) => $trip->where('commute_offer_id', $offerId),
            ));
    }

    /**
     * @return array{count: int, firstDate: string, lastDate: string}|null
     */
    public static function summary(Booking $booking): ?array
    {
        if ($booking->status !== BookingStatus::CancelledByDriver) {
            return null;
        }

        $dates = self::of($booking)
            ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
            ->orderBy('scheduled_trips.trip_date')
            ->pluck('scheduled_trips.trip_date')
            ->map(fn ($date) => substr((string) $date, 0, 10));

        if ($dates->count() < 2) {
            return null;
        }

        return [
            'count' => $dates->count(),
            'firstDate' => $dates->first(),
            'lastDate' => $dates->last(),
        ];
    }

    public static function markSeen(Booking $booking): void
    {
        self::of($booking)
            ->whereNull('bookings.outcome_seen_at')
            ->update(['outcome_seen_at' => now()]);
    }
}
