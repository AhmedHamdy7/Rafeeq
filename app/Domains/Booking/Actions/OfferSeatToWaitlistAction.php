<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
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
    public function forTrip(ScheduledTrip $trip, int $seatsFreed): int
    {
        if ($seatsFreed < 1) {
            return 0;
        }

        $eligible = SeatRequest::query()
            ->where('commute_offer_id', $trip->commute_offer_id)
            ->where('status', SeatRequestStatus::Waitlisted->value)
            /*
             * A trial waiting for Tuesday is not helped by a seat freeing on Wednesday. A recurring
             * request (no single day) wants every day it committed to, so it counts.
             */
            ->where(fn ($q) => $q
                ->whereNull('scheduled_trip_id')
                ->orWhere('scheduled_trip_id', $trip->id))
            // Somebody who needs two seats is not served by one freeing.
            ->where('seats', '<=', $seatsFreed)
            // Still waiting for a real answer; an expired request is not a person still hoping.
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            /*
             * Ordered by position even though the seat is a race. The order decides who is TOLD
             * first, which is the only advantage a position can honestly confer once acceptance is
             * first-come — and it keeps the list's displayed order meaning something.
             */
            ->orderBy('waitlist_position')
            ->orderBy('id')
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

        foreach ($eligible as $request) {
            /*
             * 🔴 `insertOrIgnore` against the unique (trip, request) index, not `firstOrCreate`.
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

            $offer = SeatOffer::query()
                ->where('scheduled_trip_id', $trip->id)
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
