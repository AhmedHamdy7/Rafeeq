<?php

namespace App\Domains\Matching\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\SameTimeBookings;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Collection;

/**
 * Where the person asking already stands with each day on a page of results (DISCOVER-6, -7).
 *
 * The detail screen has ONE action button, and which one depends on her: free seat → Request Seat;
 * full, or overlapping a seat she already holds (BOOK-17) → Request to Join Waiting List; already
 * asked → nothing to ask again (`BOOKING_ALREADY_REQUESTED`); already riding → her booking. The
 * server enforced all of it, and told her only after she tapped.
 *
 * Built once for a page — two queries whatever the number of cards — and read per day, because both
 * answers are per DATE: a recurring request covers some weekdays and not others, and a booking on
 * Tuesday says nothing about Wednesday.
 *
 * 🔒 Only ever about the viewer's own requests and bookings. `driverFirstName` on an overlap is the
 * driver she already rides with, whose name she was given when that booking was confirmed.
 */
final class PassengerStanding
{
    /** Requests still waiting on somebody, as `myStatus.kind`. */
    private const array OPEN_REQUESTS = [
        SeatRequestStatus::Pending->value => 'pending_request',
        SeatRequestStatus::Counteroffered->value => 'counteroffered',
        SeatRequestStatus::Waitlisted->value => 'waitlisted',
    ];

    /** Bookings that hold a seat she has not yet ridden. */
    private const array UPCOMING_BOOKINGS = [
        BookingStatus::Pending->value,
        BookingStatus::Confirmed->value,
    ];

    /**
     * @param  Collection<int, SeatRequest>  $requests  her open requests on these commutes
     * @param  Collection<int, Booking>  $bookings  her upcoming bookings, with their trips
     */
    private function __construct(
        private readonly Collection $requests,
        private readonly Collection $bookings,
    ) {}

    /**
     * @param  iterable<ScheduledTrip>  $trips  every day that will be rendered on the page
     */
    public static function for(?User $viewer, iterable $trips): self
    {
        $offerIds = [];
        $earliest = null;
        $latest = null;

        foreach ($trips as $trip) {
            $offerIds[$trip->commute_offer_id] = true;
            $earliest = $earliest === null || $trip->departure_at->lessThan($earliest) ? $trip->departure_at : $earliest;
            $latest = $latest === null || $trip->departure_at->greaterThan($latest) ? $trip->departure_at : $latest;
        }

        if ($viewer === null || $offerIds === []) {
            return new self(collect(), collect());
        }

        $requests = SeatRequest::query()
            ->where('passenger_user_id', $viewer->id)
            ->whereIn('commute_offer_id', array_keys($offerIds))
            ->whereIn('status', array_keys(self::OPEN_REQUESTS))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        /*
         * Every upcoming booking she holds in the window the page covers, on ANY commute: the same
         * set the overlap rule (BOOK-17) is checked against when she asks, narrowed by a day either
         * side for the same reason {@see SameTimeBookings::overlapping()} narrows it.
         */
        $bookings = Booking::query()
            ->where('passenger_user_id', $viewer->id)
            ->whereIn('status', self::UPCOMING_BOOKINGS)
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->whereBetween('departure_at', [$earliest->copy()->subDay(), $latest->copy()->addDay()])
                ->select('id'))
            ->with('scheduledTrip.commuteOffer.driverProfile.user')
            ->orderBy('id')
            ->get();

        return new self($requests, $bookings);
    }

    /**
     * `myStatus` for one day: `none` · `pending_request` · `counteroffered` · `waitlisted` · `booked`.
     *
     * @return array{kind: string, seatRequestId: string|null, bookingId: string|null}
     */
    public function statusFor(ScheduledTrip $trip): array
    {
        $booking = $this->bookings->first(fn (Booking $booking): bool => $booking->scheduled_trip_id === $trip->id);

        if ($booking !== null) {
            return ['kind' => 'booked', 'seatRequestId' => $booking->seat_request_id, 'bookingId' => $booking->id];
        }

        $request = $this->requests->first(fn (SeatRequest $request): bool => self::covers($request, $trip));

        if ($request !== null) {
            return ['kind' => self::OPEN_REQUESTS[$request->status->value], 'seatRequestId' => $request->id, 'bookingId' => null];
        }

        return ['kind' => 'none', 'seatRequestId' => null, 'bookingId' => null];
    }

    /**
     * `overlapsBooking` for one day: the seat she already holds at that time on ANOTHER commute,
     * or null (BOOK-17). When it is set, the only action is Request to Join Waiting List.
     *
     * @return array{bookingId: string, driverFirstName: string|null, departureLocal: string|null, arrivalLocal: string|null}|null
     */
    public function overlapFor(ScheduledTrip $trip): ?array
    {
        $clash = $this->bookings->first(fn (Booking $booking): bool => $booking->scheduledTrip !== null
            && $booking->scheduled_trip_id !== $trip->id
            && $booking->scheduledTrip->commute_offer_id !== $trip->commute_offer_id
            && SameTimeBookings::overlaps($trip, $booking->scheduledTrip));

        if ($clash === null) {
            return null;
        }

        $theirs = $clash->scheduledTrip;

        return [
            'bookingId' => $clash->id,
            'driverFirstName' => $theirs->commuteOffer?->driverProfile?->user?->public_first_name,
            'departureLocal' => $theirs->departure_local?->format('Y-m-d H:i:s'),
            'arrivalLocal' => self::promisedArrivalLocal($theirs),
        ];
    }

    /**
     * The driver's promised arrival on that day's own wall clock, or null where none was promised.
     */
    public static function promisedArrivalLocal(ScheduledTrip $trip): ?string
    {
        if ($trip->promised_arrival_at === null || $trip->departure_local === null) {
            return null;
        }

        $offset = $trip->departure_at->diffInSeconds($trip->promised_arrival_at, false);

        return $trip->departure_local->copy()->addSeconds((int) $offset)->format('Y-m-d H:i:s');
    }

    /**
     * Whether an open request is about this day: a one-day request names its day; a recurring one
     * covers every day of the commute on the weekdays she asked for.
     */
    private static function covers(SeatRequest $request, ScheduledTrip $trip): bool
    {
        if ($request->commute_offer_id !== $trip->commute_offer_id) {
            return false;
        }

        if ($request->commitment === SeatRequestCommitment::Recurring) {
            return $request->requested_days_mask === null
                || DaysMask::fromBits((int) $request->requested_days_mask)->includesDate($trip->trip_date);
        }

        return $request->scheduled_trip_id === null || $request->scheduled_trip_id === $trip->id;
    }
}
