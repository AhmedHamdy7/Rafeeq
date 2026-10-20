<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Enums\SwitchReason;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Booking\Support\WaitlistPositions;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\ScheduleLock;
use App\Domains\Group\Actions\AddMemberToGroupAction;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use Illuminate\Support\Facades\DB;

/**
 * A waiting passenger takes the seat she was offered (Feature 03 v3: "**first eligible
 * server-committed acceptance wins**, no 5-minute timer or driver manual selection").
 *
 * 🔴 v3 is explicit that the winner is decided by SERVER TRANSACTION ORDER, and that the user's
 * acceptance of "first accept" does **not** authorise first-device-tap priority. Those are
 * different things: whose request arrives first depends on her phone, her signal and how far she is
 * from the data centre, none of which is a fair basis for giving away a seat. So the race is
 * resolved exactly where seat approval already resolves it — a row lock on the trip — and never by
 * a timestamp the client sent.
 *
 * The lock order and the reasoning mirror `ApproveSeatRequestAction`, deliberately: two code paths
 * that both allocate the same seats must take the same lock in the same order, or they deadlock
 * instead of queueing.
 */
final readonly class AcceptSeatOfferAction
{
    public function __construct(
        private CreateBookingAction $createBooking,
        private AddMemberToGroupAction $addMember,
        private OfferSeatToWaitlistAction $offerSeat,
        private BuildNavigationItineraryAction $itinerary,
    ) {}

    /**
     * @param  Booking|null  $switchFrom  a confirmed booking she is leaving in the same breath
     * @param  bool  $joinOldWaitlist  re-add her to the OLD commute's waiting list
     */
    public function execute(
        SeatOffer $offer,
        ?Booking $switchFrom = null,
        SwitchReason $reason = SwitchReason::Voluntary,
        bool $joinOldWaitlist = false,
    ): Booking {
        /*
         * 🔴 The 16-hour lock, before the transaction opens (Feature 03 v5: "New bookings after
         * lock only when compatible with frozen commitments").
         *
         * A seat freed by somebody cancelling the night before is exactly the case this guards: the
         * day is frozen, the remaining riders have planned their mornings around its arrival, and
         * whoever takes the empty seat must not make them late. Checked here rather than inside the
         * lock because it composes the itinerary — one routing call per leg — and the whole point of
         * the lock below is to be held briefly. {@see ScheduleLock} documents the race that buys.
         */
        $this->assertKeepsTheFrozenPromise($offer);

        return DB::transaction(function () use ($offer, $switchFrom, $reason, $joinOldWaitlist): Booking {
            /*
             * 1. Lock the trip — and the one she is leaving, if this is a switch.
             *
             * 🔴 Locked in SORTED ID ORDER, always. This is the detail that cannot be got wrong by
             * a later edit: two passengers switching in opposite directions between the same pair
             * of days would, with naive ordering, each hold the lock the other needs. Sorting the
             * ids means they queue instead. The second acceptance of either seat waits here rather
             * than reading a stale `seats_taken` and passing the same check.
             */
            $tripIds = array_unique(array_filter([
                $offer->scheduled_trip_id,
                $switchFrom?->scheduled_trip_id,
            ]));

            sort($tripIds);

            /*
             * The same eager-load set `ApproveSeatRequestAction` uses, and deliberately copied
             * from it: both paths allocate a seat and then build the group, so they need the same
             * relations. Discovering them one lazy-load exception at a time is how they drift.
             */
            $locked = ScheduledTrip::query()
                ->with('commuteOffer.driverProfile', 'commuteOffer.locations', 'commuteOffer.schedule')
                ->whereIn('id', $tripIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $trip = $locked[$offer->scheduled_trip_id] ?? ScheduledTrip::query()
                ->whereKey($offer->scheduled_trip_id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Re-read the offer under that lock. By now somebody may have won it.
            $offer->refresh();

            if (! $offer->status->isLive()) {
                /*
                 * Not an error from her point of view — she is being told the truth about a race
                 * she lost — but it has to be a refusal, because the alternative is returning a
                 * booking that does not exist. The code says which so the client can say
                 * "somebody was quicker" rather than "something went wrong".
                 */
                throw DomainException::of(ErrorCode::SeatOfferClosed, fields: [
                    'status' => [$offer->status->value],
                ]);
            }

            $request = $offer->seatRequest;

            if ($request === null || $request->status !== SeatRequestStatus::Waitlisted) {
                throw DomainException::of(ErrorCode::SeatOfferClosed, fields: [
                    'status' => [SeatOfferStatus::Ineligible->value],
                ]);
            }

            /*
             * 3. The seat has to still be there. Read under the lock, so this is the live figure
             *    rather than the one the fan-out saw.
             *
             * 🔴 This is the check that makes the fan-out safe: the offer going out to five people
             * promises nothing, and the seat is allocated here, once.
             */
            /*
             * 🔴 v3: "Atomic switch must never create two overlapping confirmed bookings."
             *
             * Checked under the lock, and checked for a plain acceptance too — not only for a
             * switch. Somebody who is already riding this exact day does not need a second seat on
             * it, and the honest answer is to refuse rather than to take a seat she cannot use and
             * charge her for it. A switch AWAY from this day is excluded, because that booking is
             * about to be released a few lines below.
             */
            $alreadyRiding = Booking::query()
                ->where('scheduled_trip_id', $trip->id)
                ->where('passenger_user_id', $request->passenger_user_id)
                ->where('status', BookingStatus::Confirmed->value)
                ->when($switchFrom !== null, fn ($query) => $query->whereKeyNot($switchFrom->id))
                ->exists();

            if ($alreadyRiding) {
                $this->close($offer, SeatOfferStatus::Ineligible);

                throw DomainException::of(ErrorCode::AlreadyBooked);
            }

            if ($trip->seats_taken + $request->seats > $trip->seats_total) {
                $this->close($offer, SeatOfferStatus::TakenByOther);

                throw DomainException::of(ErrorCode::SeatOfferClosed, fields: [
                    'status' => [SeatOfferStatus::TakenByOther->value],
                ]);
            }

            // 4. She wins. The request becomes approved — the driver already approved her onto the
            //    waiting list, so there is no second decision to wait for.
            $request->forceFill([
                'status' => SeatRequestStatus::Approved->value,
                'waitlist_position' => null,
                'responded_at' => now(),
            ])->save();

            $offer->loadMissing('scheduledTrip.commuteOffer');
            $group = $this->addMember->groupFor($trip->commuteOffer);

            $booking = $this->createBooking->execute($request, $trip, $group);

            /*
             * `increment` rather than `$trip->seats_taken + $n` (pitfall #2): the former is one
             * atomic statement, the latter writes back a figure read before the lock was taken by
             * anybody else.
             */
            $trip->increment('seats_taken', $request->seats);

            // Her agreed points join this day's route (v5 §3). Bumped, not rebuilt: see
            // {@see BuildNavigationItineraryAction} on why a provider call cannot happen here.
            $this->itinerary->bump($trip->getKey());

            $this->addMember->execute($request, $trip->commuteOffer);

            if ($switchFrom !== null) {
                $this->releaseOldSeat($switchFrom, $booking, $reason, $joinOldWaitlist);
            }

            $this->close($offer, SeatOfferStatus::Accepted);

            /*
             * 5. Everybody else offered this seat learns it is gone — in the same transaction, so
             *    nobody is left holding an offer for a seat that no longer exists even if this
             *    rolls back.
             */
            SeatOffer::query()
                ->where('scheduled_trip_id', $trip->id)
                ->whereKeyNot($offer->id)
                ->open()
                ->update([
                    'status' => SeatOfferStatus::TakenByOther->value,
                    'closed_at' => now(),
                ]);

            return $booking;
        });
    }

    /**
     * The day she is accepting onto, if it is already frozen, has to survive her two points.
     *
     * Silent outside the lock window and silent on a day with no promised arrival: before the lock
     * nothing is frozen, and nothing is refused against null.
     */
    private function assertKeepsTheFrozenPromise(SeatOffer $offer): void
    {
        $trip = ScheduledTrip::query()
            ->whereKey($offer->scheduled_trip_id)
            ->whereNotNull('promised_arrival_at')
            ->with('commuteOffer.locations')
            ->first();

        if ($trip === null || ! ScheduleLock::isLocked($trip)) {
            return;
        }

        $request = $offer->seatRequest;

        if ($request === null) {
            return;
        }

        ScheduleLock::assertNewRiderKeepsThePromise(
            $this->itinerary,
            $trip,
            new Coordinate((float) $request->requested_pickup_lat, (float) $request->requested_pickup_lng),
            $request->requested_pickup_label,
            new Coordinate((float) $request->requested_dropoff_lat, (float) $request->requested_dropoff_lng),
            $request->requested_dropoff_label,
        );
    }

    /**
     * Give up the seat she is leaving, in the same transaction that gave her the new one.
     *
     * 🔴 "Atomic switch must never create two overlapping confirmed bookings" (v3). Here that is
     * structural rather than checked: the new booking and this release are one transaction, so
     * there is no instant at which both are confirmed, and if anything below fails she keeps the
     * seat she had rather than losing both.
     *
     * The old trip is already locked by the caller — in sorted id order with the new one — so the
     * seat count below cannot be read by anybody else between this read and the write.
     */
    private function releaseOldSeat(
        Booking $old,
        Booking $replacement,
        SwitchReason $reason,
        bool $joinOldWaitlist,
    ): void {
        $oldTrip = $old->scheduledTrip;

        $old->forceFill([
            /*
             * 🔴 A driver-delay switch is recorded as cancelled by the DRIVER, not by her.
             *
             * That is the true actor, and it is what keeps a driver's lateness out of a passenger's
             * own cancellation history — which matters because that history is what a future driver
             * reads when deciding whether to accept her.
             */
            'status' => $reason->cancelledByDriver()
                ? BookingStatus::CancelledByDriver->value
                : BookingStatus::CancelledByPassenger->value,
            'cancelled_at' => now(),
            'cancelled_reason' => $reason->value,
            /*
             * v3: "`DRIVER_DELAY_SWITCH` and driver-fault exit carry **no passenger cancellation
             * fee**." Voluntary follows the normal policy, which is the same config figure every
             * other cancellation uses — so there is one fee number in the system, and this decides
             * only whether it applies.
             */
            'cancellation_fee_piastres' => $reason->chargesPassenger()
                ? (int) config('rafeeq.booking.cancellation_fee_piastres')
                : 0,
        ])->save();

        // Never below zero, however the data got there.
        $release = min($old->seats_reserved, $oldTrip->seats_taken);

        if ($release > 0) {
            $oldTrip->decrement('seats_taken', $release);

            // Her old seat is somebody else's chance, offered on the way out.
            $this->offerSeat->forTrip($oldTrip->refresh(), $release);
        }

        // The day she left no longer has her two stops on it either.
        $this->itinerary->bump($oldTrip->getKey());

        BookingEventLog::record(
            $old,
            BookingEventType::Cancelled,
            $reason->cancelledByDriver() ? BookingActorType::Driver : BookingActorType::Passenger,
            $old->passenger_user_id,
            from: BookingStatus::Confirmed,
            /*
             * The link between the two bookings lives here rather than in a column. It is read by
             * support when somebody asks "what happened to my Tuesday", and a dedicated column
             * would be a second place to keep the same fact in step.
             */
            metadata: [
                'switch_reason' => $reason->value,
                'switched_to_booking_id' => $replacement->id,
            ],
        );

        if ($joinOldWaitlist) {
            $this->rejoinOldWaitlist($old);
        }
    }

    /**
     * Put her back on the old driver's waiting list, if she asked during the switch.
     *
     * 🔴 Without that driver's approval, and v3 says so outright: "If yes, backend re-adds
     * automatically **without old driver's approval**." It is not a loophole — she already held a
     * seat in that car, so the driver's consent was given and has not been withdrawn.
     *
     * 🔴 And it reserves nothing. v3: "no guarantee old recurring seat can be reclaimed... Old
     * passenger may only regain seat when it becomes free and receives a new offer." So she joins
     * the list and waits like everybody else — scoped to the same day she just left, because that
     * is the day she wanted.
     */
    private function rejoinOldWaitlist(Booking $old): void
    {
        $previous = $old->seatRequest;

        if ($previous === null) {
            return;
        }

        $rejoined = $previous->replicate([
            'status', 'waitlist_position', 'responded_by', 'responded_at', 'response_note',
            'active_request_key', 'expires_at',
        ]);

        $rejoined->status = SeatRequestStatus::Waitlisted->value;
        $rejoined->scheduled_trip_id = $old->scheduled_trip_id;
        $rejoined->expires_at = null;
        $rejoined->save();

        WaitlistPositions::renumber($old->scheduledTrip->commute_offer_id);
    }

    private function close(SeatOffer $offer, SeatOfferStatus $status): void
    {
        $offer->forceFill([
            'status' => $status->value,
            'closed_at' => now(),
        ])->save();
    }
}
