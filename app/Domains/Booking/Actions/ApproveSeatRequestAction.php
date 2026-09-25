<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\SeatApproval;
use App\Domains\Booking\Support\SeatAvailabilityChecker;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Actions\AddMemberToGroupAction;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 A driver approving a seat request. The most concurrency-sensitive operation
 * in the system.
 *
 * Pitfall #1, stated plainly: two approvals arrive in the same millisecond, both
 * read `seats_taken = 2` of 3, both pass the check, and a three-seat car is
 * carrying four people. No amount of application logic outside a lock prevents
 * this, because the gap between reading and writing is where it happens.
 *
 * So every trip row is locked BEFORE it is read, and every check runs inside that
 * lock:
 *
 *   1. `lockForUpdate()` on the trip — the second approval waits here
 *   2. the checks, now against a value nobody else can change
 *   3. the booking, the seat increment and the group membership
 *   4. commit, which releases the lock and lets the next one see the new count
 *
 * The lock is on `scheduled_trips` and nothing wider (pitfall #4): locking the
 * offer would stop every booking on every day of that commute, and locking
 * nothing at all is the bug above.
 *
 * `increment()` rather than `$trip->seats_taken + $n` (pitfall #2): the former is
 * one atomic SQL statement, the latter is a read and a write with a gap between
 * them — the same bug one level down.
 *
 * Notifications are NOT sent here (pitfall #3). Sending inside the transaction
 * means a rollback leaves a message already delivered about a booking that does
 * not exist, and it holds the lock open for the length of a network call.
 *
 * ---
 *
 * **Trial and recurring are the same operation.** A trial asked for one day; a
 * recurring member asked for a pattern of days. Both mean "seat me on the days I
 * asked for", so both run the same loop — and the Bible's scene 10 is explicit
 * that approving a recurring request generates bookings for the upcoming trips and
 * turns a trial rider into a member.
 *
 * Where they differ is what a full day means. For a trial there is only one day,
 * so if it cannot be seated the approval fails and the driver is told. For a
 * recurring membership over thirty days, refusing the whole thing because one
 * Wednesday is full would make recurring commitment nearly impossible — so those
 * days are skipped and reported in {@see SeatApproval::$skipped}.
 *
 * 🔴 Multiple locks are taken in ONE order, ascending by `trip_date`. Two
 * recurring approvals on the same commute would otherwise be able to take the
 * same two locks in opposite orders and deadlock. Consistent ordering is the whole
 * defence, and it is why the trips are re-read inside the transaction with
 * `orderBy('trip_date')` rather than locked in whatever order they were found.
 */
final readonly class ApproveSeatRequestAction
{
    public function __construct(
        private SeatAvailabilityChecker $availability,
        private CreateBookingAction $createBooking,
        private AddMemberToGroupAction $addMember,
    ) {}

    public function execute(SeatRequest $request, User $approver): SeatApproval
    {
        if ($request->status !== SeatRequestStatus::Pending
            && $request->status !== SeatRequestStatus::Waitlisted) {
            throw DomainException::of(ErrorCode::SeatRequestNotPending);
        }

        // Read outside the transaction: choosing WHICH days are candidates needs
        // no lock, and doing it inside would hold every lock for longer than the
        // writes require.
        $tripIds = $this->candidateTripIds($request);

        if ($tripIds === []) {
            // Nothing to seat them on at all — no day they asked for still exists
            // and is open.
            throw DomainException::of(ErrorCode::SeatUnavailable);
        }

        return DB::transaction(function () use ($request, $approver, $tripIds): SeatApproval {
            /*
             * The lock, in one consistent order. Everything below reads values no
             * other transaction can change until this one commits — which is the
             * entire difference between correct and the bug described above.
             */
            $trips = ScheduledTrip::query()
                ->with('commuteOffer.driverProfile', 'commuteOffer.locations', 'commuteOffer.schedule')
                ->whereIn('id', $tripIds)
                ->orderBy('trip_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $offer = $trips->first()->commuteOffer;

            // Created before the bookings so each booking can name its group, and
            // before the request is answered so a failure here leaves no trace.
            $group = $this->addMember->groupFor($offer);

            // Marked answered BEFORE the bookings are created, because a booking's
            // audit event records who approved it and reads that from here. The
            // Bible's reference sketch does it the other way round, which would
            // log a confirmation by nobody.
            $request->forceFill([
                'status' => SeatRequestStatus::Approved->value,
                'waitlist_position' => null,
                'responded_by' => $approver->id,
                'responded_at' => now(),
            ])->save();

            /** @var array<int, Booking> $bookings */
            $bookings = [];
            $skipped = [];

            foreach ($trips as $trip) {
                $refusal = $this->refusalFor($trip, $request);

                if ($refusal !== null) {
                    $skipped[$trip->trip_date->toDateString()] = $refusal->value;

                    continue;
                }

                $bookings[] = $this->createBooking->execute($request, $trip, $group);

                // Atomic in SQL. The CHECK constraint on the table is the last
                // line of defence if this ever runs without the lock.
                $trip->increment('seats_taken', $request->seats);
            }

            if ($bookings === []) {
                /*
                 * Not one day could be seated. Thrown rather than returned as an
                 * empty approval: the transaction rolls back, so the request stays
                 * open for a day when a seat does free up, and the driver is told
                 * why instead of being shown a membership with no rides in it.
                 */
                throw DomainException::of(ErrorCode::SeatUnavailable, fields: [
                    'skippedDays' => array_keys($skipped),
                ]);
            }

            // Last, because it is the one write that should not exist if any of
            // the above failed: a member of a group they have no booking in.
            $member = $this->addMember->execute($request, $offer);

            return new SeatApproval($request, $member, $bookings, $skipped);
        });
    }

    /**
     * The days this approval could seat: one for a trial, the committed pattern
     * over the horizon for a recurring membership.
     *
     * @return array<int, string>
     */
    private function candidateTripIds(SeatRequest $request): array
    {
        if ($request->commitment === SeatRequestCommitment::Trial) {
            // A trial names its day. It was validated when the request was made,
            // so a missing one here is a request that should never have existed.
            return $request->scheduled_trip_id === null
                ? []
                : [$request->scheduled_trip_id];
        }

        $mask = $request->requested_days_mask === null
            ? null
            : DaysMask::fromBits($request->requested_days_mask);

        return $this->upcomingTrips($request)
            ->filter(fn (ScheduledTrip $trip) => $mask === null || $mask->includesDate($trip->trip_date))
            ->pluck('id')
            ->all();
    }

    /**
     * @return Collection<int, ScheduledTrip>
     */
    private function upcomingTrips(SeatRequest $request): Collection
    {
        /*
         * Bounded by the same horizon that generates the trips. Seating "every
         * future trip" would mean seating whatever the generator happens to have
         * produced, which is a different number every day — and the days beyond it
         * do not exist yet to be booked.
         */
        $horizon = now()->addDays((int) config('rafeeq.booking.recurring_horizon_days'));

        return ScheduledTrip::query()
            ->where('commute_offer_id', $request->commute_offer_id)
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->where('departure_at', '<=', $horizon)
            ->orderBy('trip_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Why this one day cannot be seated, or null if it can.
     *
     * The checks are the same ones a trial approval runs; what differs is that the
     * answer is returned rather than thrown, so a recurring approval can skip a
     * full Wednesday and carry on. The DomainException is caught rather than each
     * condition being re-tested here — one implementation of "can this seat be
     * taken", asked in two ways.
     */
    private function refusalFor(ScheduledTrip $trip, SeatRequest $request): ?ErrorCode
    {
        try {
            $this->availability->assertTripIsBookable($trip);
            $this->availability->assertHasSeats($trip, $request->seats);
            $this->availability->assertDriverStillEligible($trip);
            $this->availability->assertNoDuplicateBooking($trip, $request->passenger_user_id);
        } catch (DomainException $refused) {
            if ($request->commitment === SeatRequestCommitment::Trial) {
                // One day was asked for and it cannot be seated. There is nothing
                // to skip on to, so the driver gets the real reason.
                throw $refused;
            }

            return $refused->errorCode;
        }

        return null;
    }
}
