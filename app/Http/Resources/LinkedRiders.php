<?php

namespace App\Http\Resources;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\PickupEta;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * `alsoOnLinked` for the driver (B9, S9 of 2026-11-23): whether this passenger holds a seat
 * (`booked`) or an open request (`pending`) on the commute linked to this one, on the same day
 * (recurring: on any of the same weekdays). Null when she has neither, or the commute is not linked.
 */
final class LinkedRiders
{
    public const string BOOKED = 'booked';

    public const string PENDING = 'pending';

    public static function forSeatRequest(SeatRequest $request): ?string
    {
        $offerId = (string) $request->commute_offer_id;
        $date = $request->commitment === SeatRequestCommitment::Trial ? self::dateOf($request->scheduled_trip_id) : null;
        $mask = $request->commitment === SeatRequestCommitment::Trial ? null : $request->requested_days_mask;

        return self::status((string) $request->passenger_user_id, $offerId, $date, $mask === null ? null : (int) $mask);
    }

    public static function forTrip(string $passengerId, ScheduledTrip $trip): ?string
    {
        return self::status($passengerId, (string) $trip->commute_offer_id, $trip->trip_date->toDateString(), null);
    }

    /**
     * Her seat on the linked commute on this day, for the Passenger screen's "On your return too" row
     * (round 2, item 3 of 2026-11-24). `daysMask` is the weekdays of a recurring seat; a one-day seat
     * has `daysMask` null and its `tripDate`.
     *
     * @return array{bookingId: string, commuteId: string, direction: string, pickupLabel: string|null, pickupEtaLocal: string|null, daysMask: int|null, tripDate: string|null}|null
     */
    public static function bookingFor(string $passengerId, ScheduledTrip $trip): ?array
    {
        $linked = self::linkedId((string) $trip->commute_offer_id);

        if ($linked === null) {
            return null;
        }

        $booking = Booking::query()
            ->where('passenger_user_id', $passengerId)
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->where('commute_offer_id', $linked)
                ->where('trip_date', $trip->trip_date->toDateString())
                ->select('id'))
            ->with(['scheduledTrip.commuteOffer', 'scheduledTrip.navigationStops', 'seatRequest'])
            ->first();

        if ($booking === null) {
            return null;
        }

        $day = $booking->scheduledTrip;
        $request = $booking->seatRequest;
        $recurring = $request !== null && $request->commitment !== SeatRequestCommitment::Trial;
        $mask = $recurring
            ? (int) ($request->requested_days_mask ?? $day->commuteOffer->schedule()->value('days_mask') ?? 0)
            : null;

        return [
            'bookingId' => (string) $booking->getKey(),
            'commuteId' => $linked,
            'direction' => $day->commuteOffer->direction->value,
            'pickupLabel' => $booking->pickup_label,
            'pickupEtaLocal' => TripTimes::clock(PickupEta::local(
                $day,
                PickupEta::forBooking(app(GeoQueryEngine::class), $booking, $day->navigationStops),
            )),
            'daysMask' => $mask,
            'tripDate' => $recurring ? null : $day->trip_date->toDateString(),
        ];
    }

    public static function status(string $passengerId, string $offerId, ?string $date, ?int $mask): ?string
    {
        $linked = self::linkedId($offerId);

        if ($linked === null) {
            return null;
        }

        $trips = ScheduledTrip::query()
            ->where('commute_offer_id', $linked)
            ->where('departure_at', '>', now()->subDay())
            ->when($date !== null, fn ($query) => $query->where('trip_date', $date))
            ->get(['id', 'trip_date'])
            ->filter(fn (ScheduledTrip $trip): bool => $mask === null || DaysMask::fromBits($mask)->includesDate($trip->trip_date));

        if ($trips->isNotEmpty() && Booking::query()
            ->where('passenger_user_id', $passengerId)
            ->whereIn('scheduled_trip_id', $trips->pluck('id'))
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
            ->exists()) {
            return self::BOOKED;
        }

        $requests = SeatRequest::query()
            ->where('passenger_user_id', $passengerId)
            ->where('commute_offer_id', $linked)
            ->whereIn('status', [SeatRequestStatus::Pending->value, SeatRequestStatus::Counteroffered->value, SeatRequestStatus::Waitlisted->value])
            ->get(['id', 'commitment', 'scheduled_trip_id', 'requested_days_mask']);

        $tripIds = $trips->pluck('id')->map(fn ($id): string => (string) $id)->all();

        foreach ($requests as $open) {
            if ($open->commitment === SeatRequestCommitment::Trial) {
                if (in_array((string) $open->scheduled_trip_id, $tripIds, true)) {
                    return self::PENDING;
                }

                continue;
            }

            $theirs = $open->requested_days_mask === null ? 127 : (int) $open->requested_days_mask;

            if ($date !== null ? DaysMask::fromBits($theirs)->includesDate(CarbonImmutable::parse($date)) : ($theirs & ($mask ?? 127)) > 0) {
                return self::PENDING;
            }
        }

        return null;
    }

    public static function linkedId(string $offerId): ?string
    {
        $offer = CommuteOffer::query()->whereKey($offerId)->first(['id', 'direction', 'return_of_commute_id']);

        if ($offer === null) {
            return null;
        }

        $linked = $offer->isReturn()
            ? $offer->return_of_commute_id
            : CommuteOffer::query()
                ->where('return_of_commute_id', $offerId)
                ->whereIn('status', [CommuteOfferStatus::Published->value, CommuteOfferStatus::Paused->value])
                ->value('id');

        return $linked === null ? null : (string) $linked;
    }

    private static function dateOf(?string $tripId): ?string
    {
        if ($tripId === null) {
            return null;
        }

        $date = ScheduledTrip::query()->whereKey($tripId)->value('trip_date');

        return $date instanceof CarbonInterface ? $date->toDateString() : ($date === null ? null : substr((string) $date, 0, 10));
    }
}
