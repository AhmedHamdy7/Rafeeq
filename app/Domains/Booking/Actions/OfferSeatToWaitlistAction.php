<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Support\WaitlistEligibility;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Outbox\Enums\OutboxEventType;
use App\Domains\Outbox\Support\Outbox;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\Support\PickupReachability;
use Illuminate\Support\Str;

/**
 * A seat came free, so everybody waiting for it is offered it at once (Feature 03 v3).
 *
 * 🔴 This replaces promoting one person, and it reverses an argument the codebase made in writing.
 * `PromoteFromWaitlistAction` said: "promoting the whole queue would turn a single cancellation into
 * five hopeful people competing for one seat, and four of them finding out by being refused."
 *
 * That cost is real. What changed is what the alternative cost:
 *
 *   - promotion moved one person to `pending`, where she still needed the driver to approve her. So
 *     a freed seat could sit empty for up to 48 hours waiting on one reply, with four people
 *     waiting behind her — and she could still be refused at the end of it.
 *   - fan-out offers it to people the driver has ALREADY approved: she approved them when she put
 *     them on the waiting list. So the first acceptance is the seat, and the others are not refused
 *     — they are told somebody was quicker, which is what a waiting list means.
 *
 * The rule that the driver decides who rides with her is untouched. She decides it earlier.
 *
 * Called from inside the cancelling transaction, like the promotion it replaces: if the
 * cancellation rolls back, the seat never freed and nobody should have been told otherwise.
 */
final readonly class OfferSeatToWaitlistAction
{
    /**
     * @param  int  $seatsFreed  how many seats the cancellation released
     * @return int how many people were offered the seat
     */
    /**
     * Close open offers on runs that can no longer reach the person they were made to
     * (Feature 03 v5 seat-allocation point 6, and its required test 4).
     *
     * > *"Trip starts while offer is open: offer remains if pickup reachable; **closes once route
     * > makes pickup unreachable**."* And: *"If pickup unreachable or trip finishes, close with
     * > `INELIGIBLE`/`CLOSED`."*
     *
     * 🔴 **`Ineligible`, not `Closed`.** The seat still exists — she simply stopped qualifying for
     * it, which is what that status was written for, and the distinction is for the woman reading
     * her own history: "the car went past my street" is a different morning from "the day was
     * cancelled".
     *
     * ⚠️ Only runs that are UNDER WAY are examined. Before a run sets off there is no route
     * progress to measure and nothing has become unreachable — v5 is explicit that "trip start
     * alone doesn't invalidate offers", and so is its opposite: an offer on tomorrow's commute is
     * not unreachable, it is simply not yet asked.
     *
     * @return int how many were closed
     */
    public function closeUnreachable(PickupReachability $reachability): int
    {
        $open = SeatOffer::query()
            ->open()
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->whereIn('id', TripSession::query()
                    ->whereIn('current_status', TripSessionStatus::underwayValues())
                    ->select('scheduled_trip_id'))
                ->select('id'))
            ->with('seatRequest', 'scheduledTrip.commuteOffer.locations', 'scheduledTrip.tripSession')
            ->get();

        $closed = 0;

        foreach ($open as $offer) {
            $request = $offer->seatRequest;
            $trip = $offer->scheduledTrip;

            if ($request === null || $trip === null) {
                continue;
            }

            $pickup = new Coordinate(
                (float) $request->requested_pickup_lat,
                (float) $request->requested_pickup_lng,
            );

            if ($reachability->isReachable($trip, $pickup)) {
                continue;
            }

            $offer->forceFill([
                'status' => SeatOfferStatus::Ineligible->value,
                'closed_at' => now(),
            ])->save();

            $closed++;
        }

        return $closed;
    }

    public function forTrip(ScheduledTrip $trip, int $seatsFreed): int
    {
        if ($seatsFreed < 1) {
            return 0;
        }

        $eligible = WaitlistEligibility::requestsForTrip($trip, $seatsFreed)
            // Eager-loaded because each one is notified below, and strict mode turns a lazy load
            // inside that loop into an exception — or, in production, a query per waiting person.
            ->with('passenger')
            ->get();

        if ($eligible->isEmpty()) {
            return 0;
        }

        /*
         * One id for the whole fan-out, so a single cancellation is one event in the data however
         * many people it reached — which is what makes "close the rest" a single statement and
         * makes the history legible later.
         */
        $releaseEventId = (string) Str::ulid();
        $offered = 0;

        /*
         * 🔴 v5 specifies this one by name: *"Seat release ... emits
         * `seat_opened(occurrence_id, seat_count, event_id)` **once via outbox**"* — and the
         * `event_id` it means is this fan-out's own `release_event_id`, which every offer below
         * also carries. The same id on the announcement and on the offers is what lets a client tie
         * the two together instead of guessing which release an offer belongs to.
         *
         * Written in this transaction, like everything else here: if the cancellation that freed
         * the seat rolls back, the announcement goes with it. A client told "a seat opened" for a
         * seat that never freed would reach for a chair that was never empty.
         *
         * Deduped on the release id, so a cancellation processed twice announces one opening rather
         * than offering two chances at one seat.
         */
        Outbox::record(
            OutboxEventType::SeatOpened,
            Outbox::occurrenceChannel((string) $trip->getKey()),
            [
                'occurrenceId' => (string) $trip->getKey(),
                'commuteId' => (string) $trip->commute_offer_id,
                'seatCount' => $seatsFreed,
                'releaseEventId' => $releaseEventId,
            ],
            dedupeKey: "seat.opened:{$releaseEventId}",
        );

        foreach ($eligible as $request) {
            /*
             * 🔴 Not if she already has a LIVE offer for this day.
             *
             * This check used to be a side effect of a unique index on `(trip, request)`, and that
             * index was wrong in a way that emptied cars: it forbade a second offer for one day for
             * ever, so when a second seat freed on a Tuesday whose first fan-out had already closed,
             * `insertOrIgnore` silently ignored everybody and nobody was told. See the migration
             * `key_seat_offers_by_release_not_journey`.
             *
             * Asked explicitly here instead, where it says what it means: do not give one person two
             * live chances at the same morning. A CLOSED offer is no obstacle — a woman who declined
             * Tuesday's seat, or lost it to somebody quicker, is still waiting for Tuesday.
             */
            if (SeatOffer::hasLiveOfferFor((string) $trip->getKey(), (string) $request->id)) {
                continue;
            }

            /*
             * 🔴 `insertOrIgnore` against the unique (request, release) index, not `firstOrCreate`.
             *
             * Two reasons, and both matter. It leans on the index instead of racing it: a
             * cancellation processed twice — a retried job, an overlapping manual run — cannot give
             * one person two chances at the same seat or create two acceptances to reconcile.
             * And `firstOrCreate` MASS-ASSIGNS, which this model refuses on purpose: a fillable
             * `status` here would let a client accept an offer by including a field in an unrelated
             * payload, and the field in question is the seat.
             *
             * The return value is the row count, so 1 means this person was newly offered it and 0
             * means she already had been — which is exactly the test for whether to notify.
             */
            $inserted = SeatOffer::query()->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'scheduled_trip_id' => $trip->id,
                'seat_request_id' => $request->id,
                'status' => SeatOfferStatus::Open->value,
                'release_event_id' => $releaseEventId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // A re-run must not notify twice.
            if ($inserted === 0) {
                continue;
            }

            /*
             * Found by the RELEASE, not by the day. She may hold a closed offer for this same
             * Tuesday from an earlier freeing — which is legal now and is the whole point of the
             * key change — and a lookup by `(trip, request)` would find two rows and throw.
             */
            $offer = SeatOffer::query()
                ->where('release_event_id', $releaseEventId)
                ->where('seat_request_id', $request->id)
                ->sole();

            $offered++;

            if ($request->passenger !== null) {
                Notifier::send($request->passenger, NotificationType::SeatOffered, params: [
                    'date' => $trip->trip_date->toFormattedDateString(),
                ], data: [
                    'offerId' => $offer->id,
                    'tripId' => $trip->id,
                    'commuteId' => $trip->commute_offer_id,
                ]);
            }
        }

        return $offered;
    }
}
