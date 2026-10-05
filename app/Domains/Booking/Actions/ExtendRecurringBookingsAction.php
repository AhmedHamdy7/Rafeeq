<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\SeatAvailabilityChecker;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Enums\GroupMemberRole;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 Keeps a committed member seated as the horizon rolls forward.
 *
 * This closes the same silent failure that `commutes:generate-trips` closes one
 * level up, and it is worth stating plainly because the failure is invisible:
 * approving a recurring request seats the member on the 30 days that exist at that
 * moment. Ten days later the generator has created ten new days — and nobody is
 * booked on them. The member believes they are a committed member of the group,
 * their group screen says so, and one morning in five weeks the car does not stop
 * for them because they have no booking.
 *
 * So every active member with a committed pattern gets seated on any newly
 * generated day that matches it and that they are not already booked on.
 *
 * The rules a fresh approval obeys are obeyed here too, and for the same reasons:
 * the trip row is locked before its seat count is read, the seat is incremented in
 * one atomic statement, and a day that is full is skipped rather than forced. A
 * membership does not outrank the seat count.
 *
 * Idempotent by (trip, passenger) — both because it checks, and because the unique
 * index on `bookings` would refuse a second row anyway.
 */
final readonly class ExtendRecurringBookingsAction
{
    public function __construct(
        private SeatAvailabilityChecker $availability,
        private CreateBookingAction $createBooking,
    ) {}

    /**
     * @return int how many bookings were created
     */
    public function execute(): int
    {
        $created = 0;

        GroupMember::query()
            ->where('status', GroupMemberStatus::Active->value)
            // A driver has no booking on their own commute, and a trial rider
            // committed to one day, not a pattern.
            ->where('role', GroupMemberRole::Member->value)
            ->whereNotNull('committed_days_mask')
            /*
             * A suspended member keeps the days already booked — the restricted-account
             * screen promises "your current group can still see your attendance" — but
             * gets no new ones. Extending them would be booking on behalf of somebody
             * the platform has just stopped from booking.
             */
            ->whereHas('user', fn ($user) => $user->where('account_status', AccountStatus::Active->value))
            ->with('commuteGroup')
            ->chunkById(200, function ($members) use (&$created): void {
                foreach ($members as $member) {
                    $created += $this->seatMember($member);
                }
            });

        return $created;
    }

    private function seatMember(GroupMember $member): int
    {
        $offerId = $member->commuteGroup->commute_offer_id;

        // The request the driver approved. Its price snapshot, seat count and
        // payment type are what every extension booking inherits — a member's terms
        // do not change because a month went by (pitfall #13).
        $request = SeatRequest::query()
            ->where('passenger_user_id', $member->user_id)
            ->where('commute_offer_id', $offerId)
            ->where('status', SeatRequestStatus::Approved->value)
            ->latest('responded_at')
            ->orderByDesc('id')
            ->first();

        if ($request === null) {
            // A membership with no approved request behind it. Nothing to inherit
            // terms from, so nothing is invented.
            return 0;
        }

        $mask = DaysMask::fromBits($member->committed_days_mask);
        $created = 0;

        foreach ($this->unbookedTrips($member, $offerId) as $trip) {
            if (! $mask->includesDate($trip->trip_date)) {
                continue;
            }

            $created += $this->seatOn($trip->id, $request, $member) ? 1 : 0;
        }

        return $created;
    }

    /**
     * One transaction per day, not one for the whole member.
     *
     * A single transaction over thirty trips would hold thirty row locks while it
     * worked, blocking every driver trying to approve somebody on any of those days.
     * A day at a time keeps each lock to a single insert and increment.
     */
    private function seatOn(string $tripId, SeatRequest $request, GroupMember $member): bool
    {
        return DB::transaction(function () use ($tripId, $request, $member): bool {
            $trip = ScheduledTrip::query()
                ->with('commuteOffer.driverProfile')
                ->lockForUpdate()
                ->find($tripId);

            if ($trip === null) {
                return false;
            }

            try {
                $this->availability->assertTripIsBookable($trip);
                $this->availability->assertHasSeats($trip, $request->seats);
                $this->availability->assertDriverStillEligible($trip);
                $this->availability->assertNoDuplicateBooking($trip, $member->user_id);
            } catch (DomainException) {
                // Full, closed, or already booked. Skipped, because the alternative
                // is forcing a seat that is not there.
                return false;
            }

            $this->createBooking->execute($request, $trip, $member->commuteGroup);

            $trip->increment('seats_taken', $request->seats);

            return true;
        });
    }

    /**
     * The member's future days on this commute that have no booking yet.
     *
     * `whereNotIn` against their own bookings rather than a left join: the set of
     * one person's bookings on one commute is tiny, and the subquery keeps the
     * outer plan on the (offer, date) index.
     *
     * @return Collection<int, ScheduledTrip>
     */
    private function unbookedTrips(GroupMember $member, string $offerId)
    {
        return ScheduledTrip::query()
            ->where('commute_offer_id', $offerId)
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->whereNotIn('id', Booking::query()
                ->where('passenger_user_id', $member->user_id)
                ->whereIn('status', [
                    BookingStatus::Pending->value,
                    BookingStatus::Confirmed->value,
                    BookingStatus::Completed->value,
                    /*
                     * A cancelled booking counts as "already handled" here on
                     * purpose. Someone who cancelled Thursday does not want a job
                     * putting Thursday back tomorrow morning — re-seating them
                     * would overrule a decision they made.
                     */
                    BookingStatus::CancelledByPassenger->value,
                    BookingStatus::CancelledByDriver->value,
                ])
                ->select('scheduled_trip_id'))
            ->orderBy('trip_date')
            ->orderBy('id')
            ->get();
    }
}
