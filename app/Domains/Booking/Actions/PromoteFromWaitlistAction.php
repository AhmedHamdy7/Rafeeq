<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;

/**
 * A seat came free, so the person who was waiting for it gets their turn.
 *
 * 🔴 Promotion means `waitlisted` → `pending`. It does NOT create a booking, and
 * that is the whole point: in Rafeeq the driver decides who rides with them.
 * Seating someone automatically because a seat opened would put a stranger in
 * their car without them ever having said yes — the one rule the product cannot
 * bend. So the request moves into the driver's inbox, where it is answered like
 * any other.
 *
 * Only ONE person is promoted per freed seat. Promoting the whole queue would
 * turn a single cancellation into five hopeful people competing for one seat, and
 * four of them finding out by being refused.
 *
 * Called from inside the cancelling transaction, deliberately: if the cancellation
 * rolls back, the seat never actually freed and nobody should have been told
 * otherwise. Nothing here reaches the network, so it holds the lock for the length
 * of two indexed statements.
 */
final readonly class PromoteFromWaitlistAction
{
    /**
     * @param  int  $seatsFreed  how many seats the cancellation released
     * @return SeatRequest|null the request that was promoted, if any
     */
    public function forTrip(ScheduledTrip $trip, int $seatsFreed): ?SeatRequest
    {
        if ($seatsFreed < 1) {
            return null;
        }

        $next = SeatRequest::query()
            ->where('commute_offer_id', $trip->commute_offer_id)
            ->where('status', SeatRequestStatus::Waitlisted->value)
            // A trial waiting for Tuesday is not helped by a seat freeing on
            // Wednesday. A recurring request (no single day) wants every day it
            // committed to, so this one counts.
            ->where(fn ($q) => $q
                ->whereNull('scheduled_trip_id')
                ->orWhere('scheduled_trip_id', $trip->id))
            // Someone who needs two seats is not served by one freeing.
            ->where('seats', '<=', $seatsFreed)
            // Still waiting for a real answer: an expired request is not a person
            // who is still hoping.
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            /*
             * The queue's order, with the id as a tiebreak so two requests
             * recorded in the same second cannot swap places between reads
             * (standard #42).
             */
            ->orderBy('waitlist_position')
            ->orderBy('id')
            ->first();

        if ($next === null) {
            return null;
        }

        $next->forceFill([
            'status' => SeatRequestStatus::Pending->value,
            'waitlist_position' => null,
            /*
             * The clock restarts. The 48 hours it was given were spent waiting
             * for a seat, not waiting for an answer, and expiring a request the
             * driver has only just been shown would refuse it on their behalf.
             */
            'expires_at' => now()->addHours((int) config('rafeeq.booking.request_expiry_hours')),
        ])->save();

        $this->renumber($trip->commute_offer_id);

        return $next;
    }

    /**
     * Renumber the queue so the positions shown to people are 1, 2, 3 and not
     * 2, 4, 7.
     *
     * The numbers are what a waiting passenger actually reads — "you are third in
     * line" — so a gap is not cosmetic: it makes the queue look longer than it is
     * and makes movement through it invisible.
     *
     * A row at a time, on the primary key. That is one statement per waiting
     * person, and `max_waitlist_size` caps the queue at ten — small enough that a
     * clever single-statement CASE would buy nothing and cost the next reader
     * their afternoon. Ordered by the position each one already held, so nobody
     * overtakes anybody by being renumbered.
     *
     * Public because leaving the queue any other way opens the same gap: a
     * withdrawal or a refusal takes somebody out of the middle of it, and the people
     * behind them are then told they are further back than they are.
     */
    public function renumber(string $commuteOfferId): void
    {
        $waiting = SeatRequest::query()
            ->where('commute_offer_id', $commuteOfferId)
            ->where('status', SeatRequestStatus::Waitlisted->value)
            ->orderBy('waitlist_position')
            ->orderBy('id')
            ->get();

        foreach ($waiting->values() as $index => $request) {
            $position = $index + 1;

            if ($request->waitlist_position === $position) {
                continue;
            }

            $request->forceFill(['waitlist_position' => $position])->save();
        }
    }
}
