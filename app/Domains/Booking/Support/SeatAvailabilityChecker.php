<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

/**
 * The checks that decide whether a seat can actually be taken.
 *
 * Every method here MUST be called from inside a transaction that already holds
 * a row lock on the trip. On its own this class proves nothing: reading
 * `seats_taken` without the lock is exactly pitfall #1 — two requests in the same
 * millisecond both see 2 of 3, both pass, and a three-seat car carries four
 * people.
 *
 * It exists as its own class because the same questions are asked from more than
 * one place (approving a request, and later confirming a booking), and because
 * the Bible's reference implementation names it as a collaborator.
 */
final class SeatAvailabilityChecker
{
    /**
     * @param  ScheduledTrip  $trip  MUST have been fetched with `lockForUpdate()`
     */
    public function assertHasSeats(ScheduledTrip $trip, int $seats): void
    {
        if ($trip->seats_taken + $seats > $trip->seats_total) {
            throw DomainException::of(ErrorCode::SeatUnavailable, fields: [
                'seatsAvailable' => [(string) max(0, $trip->seats_total - $trip->seats_taken)],
            ]);
        }
    }

    /**
     * A trip that is cancelled, already under way, or past its booking deadline
     * cannot take anyone new — however many seats the column still says are free.
     */
    public function assertTripIsBookable(ScheduledTrip $trip): void
    {
        if ($trip->status !== ScheduledTripStatus::Scheduled) {
            throw DomainException::of(ErrorCode::SeatUnavailable);
        }

        if ($trip->departure_at->isPast()) {
            throw DomainException::of(ErrorCode::BookingDeadlinePassed);
        }

        if ($trip->booking_deadline_at !== null && $trip->booking_deadline_at->isPast()) {
            throw DomainException::of(ErrorCode::BookingDeadlinePassed);
        }
    }

    /**
     * Re-checked at approval, not only when the passenger searched: a driver can
     * be suspended or let a licence lapse between a request being made and being
     * answered, and approving then would seat someone with a driver who is no
     * longer allowed to carry them.
     */
    public function assertDriverStillEligible(ScheduledTrip $trip): void
    {
        $profile = $trip->commuteOffer->driverProfile;

        if ($profile->status !== DriverProfileStatus::Approved || ! $profile->hasValidLicence()) {
            throw DomainException::of(ErrorCode::SeatUnavailable);
        }

        // The account as well as the profile — see HardFilters::requireApprovedDriver().
        if ($profile->user->account_status !== AccountStatus::Active) {
            throw DomainException::of(ErrorCode::SeatUnavailable);
        }

        if (! $trip->commuteOffer->isPublished()) {
            throw DomainException::of(ErrorCode::SeatUnavailable);
        }
    }

    /**
     * One LIVE booking per person per day. The generated unique column
     * `bookings.live_booking_key` is what makes it true under a race; this gives the
     * caller an explanation instead of a constraint violation.
     *
     * Cancelled bookings do not count — someone who cancelled and changed their
     * mind should be able to rebook.
     *
     * ⚠️ These three statuses ARE the generated column's status list, and they must
     * stay identical. When they differed, the index refused a rebooking the checker
     * had just allowed, and the passenger got a 500 instead of a seat. See the
     * migration `scope_booking_uniqueness_to_live_bookings`.
     */
    public function assertNoDuplicateBooking(ScheduledTrip $trip, string $passengerUserId): void
    {
        $exists = Booking::query()
            ->where('scheduled_trip_id', $trip->id)
            ->where('passenger_user_id', $passengerUserId)
            ->whereIn('status', [
                BookingStatus::Pending->value,
                BookingStatus::Confirmed->value,
                BookingStatus::Completed->value,
            ])
            ->exists();

        if ($exists) {
            throw DomainException::of(ErrorCode::AlreadyBooked);
        }
    }
}
