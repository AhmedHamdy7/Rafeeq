<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Actions\AddMemberToGroupAction;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
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
    ) {}

    public function execute(SeatOffer $offer): Booking
    {
        return DB::transaction(function () use ($offer): Booking {
            /*
             * 1. Lock the trip. A second acceptance of the same seat waits here rather than reading
             *    a stale `seats_taken` and passing the same check.
             */
            $trip = ScheduledTrip::query()
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

            $offer->loadMissing("scheduledTrip.commuteOffer");
            $group = $this->addMember->groupFor($trip->commuteOffer);

            $booking = $this->createBooking->execute($request, $trip, $group);

            /*
             * `increment` rather than `$trip->seats_taken + $n` (pitfall #2): the former is one
             * atomic statement, the latter writes back a figure read before the lock was taken by
             * anybody else.
             */
            $trip->increment('seats_taken', $request->seats);

            $this->addMember->execute($request, $trip->commuteOffer);

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

    private function close(SeatOffer $offer, SeatOfferStatus $status): void
    {
        $offer->forceFill([
            'status' => $status->value,
            'closed_at' => now(),
        ])->save();
    }
}
