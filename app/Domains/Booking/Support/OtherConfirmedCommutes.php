<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;

/**
 * Which passengers already hold a confirmed future seat on a commute OTHER than the one a driver is
 * looking at — the `hasOtherConfirmedCommute` flag (Feature 03 R-2, BOOK-34).
 *
 * Asked from two screens: the driver's waiting list and his request inbox. One query for the whole
 * page rather than one per card, and one implementation so the two screens cannot disagree about
 * the same woman.
 *
 * 🔒 It returns which commutes she rides on only so the caller can exclude its own; what reaches
 * the driver is a bare boolean per card. He learns that she may already be covered, never which
 * commute, which driver or which days.
 */
final class OtherConfirmedCommutes
{
    /**
     * Passenger id → the commute ids she holds a confirmed future seat on.
     *
     * @param  iterable<int, string>  $passengerIds
     * @return array<string, list<string>>
     */
    public static function forPassengers(iterable $passengerIds): array
    {
        $ids = collect($passengerIds)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $rows = Booking::query()
            ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
            ->whereIn('bookings.passenger_user_id', $ids)
            ->where('bookings.status', BookingStatus::Confirmed->value)
            ->where('scheduled_trips.departure_at', '>', now())
            ->distinct()
            ->orderBy('bookings.passenger_user_id')
            ->get(['bookings.passenger_user_id', 'scheduled_trips.commute_offer_id']);

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->passenger_user_id][] = (string) $row->commute_offer_id;
        }

        return $map;
    }

    /**
     * Whether, by that map, she rides on any commute but this one.
     *
     * @param  array<string, list<string>>  $map
     */
    public static function elsewhere(array $map, string $passengerId, string $commuteId): bool
    {
        foreach ($map[$passengerId] ?? [] as $other) {
            if ($other !== $commuteId) {
                return true;
            }
        }

        return false;
    }

}
