<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\WaitlistPositions;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * The answers to a seat request that are not an approval: a refusal by the driver,
 * a withdrawal by the passenger, and the two sweeps that close requests which can no
 * longer be answered — a during-ride request past its three minutes, and a one-day
 * request whose day has gone by.
 *
 * 🔴 Nothing else closes a request (Feature 03 R-4, BOOK-36, decision C2-note). It stays
 * until the driver accepts or rejects it, she withdraws it, or its day passes. Waiting-
 * list places never expire either.
 *
 * Approval lives in its own Action because it is the one that touches seats under
 * a lock. These three change only the request itself.
 */
final readonly class RespondToSeatRequestAction
{
    /**
     * A driver declining. The note is optional here, unlike a verification
     * rejection: a driver choosing who rides in their own car does not owe a
     * justification, and demanding one would produce boxes filled with "no".
     */
    public function reject(SeatRequest $request, User $driver, ?string $note = null): SeatRequest
    {
        $this->assertOpen($request);

        $wasWaiting = $request->status === SeatRequestStatus::Waitlisted;

        $request->forceFill([
            'status' => SeatRequestStatus::Rejected->value,
            'waitlist_position' => null,
            'responded_by' => $driver->id,
            'responded_at' => now(),
            'response_note' => $note,
        ])->save();

        $this->closeTheirPlaceInTheQueue($request, $wasWaiting);

        // The driver's note is not repeated here: it may be personal, and a push body is shown
        // on a lock screen. The passenger reads it on the request itself.
        Notifier::send($request->passenger, NotificationType::SeatDeclined,
            ['name' => $driver->public_first_name],
            ['seatRequestId' => $request->id],
        );

        return $request;
    }

    /**
     * "Waitlist" — the driver's third answer, and screen 28's third button.
     *
     * Not a yes and not a no: "I would take you, but not this week." A driver whose car is
     * full today should be able to keep somebody rather than refuse them, and refusing was
     * the only option this Action offered — so the passenger's alternative was to be
     * declined and have to ask again, on a commute they had already been judged suitable
     * for.
     *
     * 🔴 Also how a waiting-list application is approved (Feature 03 R-1, BOOK-18): she asked
     * to join his list — because the day was full, or because she already rides with somebody
     * else at that time — and this is his yes. Nothing joins a waiting list without it. It
     * works on a seat request too, even on a day with a free seat: that is the driver choosing.
     */
    public function waitlist(SeatRequest $request, User $driver, ?string $note = null): SeatRequest
    {
        if ($request->status !== SeatRequestStatus::Pending) {
            // Only a pending request can be parked. One already waiting has nowhere to go,
            // and an answered one is not a conversation any more.
            throw DomainException::of(ErrorCode::SeatRequestNotPending);
        }

        return DB::transaction(function () use ($request, $driver, $note): SeatRequest {
            /*
             * The position is taken here rather than left null: the queue's order is what
             * the passenger is shown ("you are second in line"), and a member of it with
             * no place in it would sort unpredictably against the ones that have.
             */
            $position = $this->waitlistPositionFor($request);

            $request->forceFill([
                'status' => SeatRequestStatus::Waitlisted->value,
                'waitlist_position' => $position,
                'responded_by' => $driver->id,
                'responded_at' => now(),
                'response_note' => $note,
                /*
                 * A waiting-list place never expires (Feature 03 R-4): it lasts until a seat
                 * goes to her, she leaves, or the driver removes her.
                 */
                'expires_at' => null,
            ])->save();

            return $request;
        });
    }

    /**
     * The back of the queue, taken from the highest place in use rather than from how many
     * are waiting — see {@see RequestSeatAction::nextWaitlistPosition()} for why a count
     * hands out a position somebody is already standing in.
     */
    private function waitlistPositionFor(SeatRequest $request): int
    {
        $waiting = SeatRequest::query()
            ->where('commute_offer_id', $request->commute_offer_id)
            ->where('status', SeatRequestStatus::Waitlisted->value);

        if ($waiting->clone()->count() >= (int) config('rafeeq.booking.max_waitlist_size')) {
            throw DomainException::of(ErrorCode::WaitlistFull);
        }

        return (int) $waiting->max('waitlist_position') + 1;
    }

    /**
     * The passenger changing their mind before an answer. Withdrawn rather than
     * rejected, so a driver's refusal rate is not inflated by requests they never
     * saw.
     */
    public function withdraw(SeatRequest $request): SeatRequest
    {
        $this->assertOpen($request);

        $wasWaiting = $request->status === SeatRequestStatus::Waitlisted;

        $request->forceFill([
            'status' => SeatRequestStatus::Withdrawn->value,
            'waitlist_position' => null,
            'responded_at' => now(),
        ])->save();

        $this->closeTheirPlaceInTheQueue($request, $wasWaiting);

        return $request;
    }

    /**
     * During-ride requests nobody answered in their three minutes (Feature 03 v5 §416).
     *
     * 🔴 The only requests that carry an `expires_at` any more (R-4): every other one is
     * written with none, and the migration that removed the 48-hour rule cleared it from the
     * rows that already had one. The status list stays as it was so a during-ride request is
     * closed in whatever state it reached.
     *
     * @return int how many expired
     */
    public static function expireOverdue(): int
    {
        return SeatRequest::query()
            ->whereIn('status', [
                SeatRequestStatus::Pending->value,
                SeatRequestStatus::Waitlisted->value,
                /*
                 * A counteroffer nobody answers has to close too. It holds the passenger's one open
                 * request for that commute, so leaving it would mean she cannot ask again while
                 * waiting to decide on an offer she has stopped thinking about — and the driver
                 * would be holding a place open for a conversation that ended.
                 */
                SeatRequestStatus::Counteroffered->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => SeatRequestStatus::Expired->value]);
    }

    /**
     * One-day requests whose day has gone by (Feature 03 R-4: "a one-day request whose date has
     * passed closes").
     *
     * 🔴 Needed now that requests do not expire: a trial for last Tuesday is not a question
     * anybody can answer, and left open it would hold her one open request on that commute.
     * Closed `closed_date_passed`, not `expired` — nobody failed to answer in time; the day simply
     * passed, and `expired` would read as a mark against the driver.
     *
     * One-day means a `trial`, or a waiting-list place pinned to one date (a single-date switch
     * puts her back on the old list for that morning only). A recurring request that happens to
     * name a first day is about a pattern, and stays.
     *
     * "Passed" is the commute's own calendar: the day before today in Cairo, where every
     * commute's days are written — a 07:00 run is not "yesterday" at 01:00 UTC on the same date.
     *
     * @return int how many closed
     */
    public static function closeDatePassed(): int
    {
        $today = now('Africa/Cairo')->toDateString();

        $ids = SeatRequest::query()
            ->whereIn('status', [
                SeatRequestStatus::Pending->value,
                SeatRequestStatus::Waitlisted->value,
                SeatRequestStatus::Counteroffered->value,
            ])
            ->whereNotNull('scheduled_trip_id')
            ->where(fn ($query) => $query
                ->where('commitment', SeatRequestCommitment::Trial->value)
                ->orWhere('status', SeatRequestStatus::Waitlisted->value))
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->where('trip_date', '<', $today)
                ->select('id'))
            ->orderBy('id')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $offers = SeatRequest::query()
            ->whereIn('id', $ids->all())
            ->where('status', SeatRequestStatus::Waitlisted->value)
            ->distinct()
            ->pluck('commute_offer_id');

        $closed = SeatRequest::query()
            ->whereIn('id', $ids->all())
            ->update([
                'status' => SeatRequestStatus::ClosedDatePassed->value,
                'waitlist_position' => null,
            ]);

        // The places behind them move up, so nobody reads "third in line" when she is second.
        foreach ($offers as $offerId) {
            WaitlistPositions::renumber((string) $offerId);
        }

        return $closed;
    }

    /**
     * Somebody leaving the middle of the queue would otherwise leave a hole in it,
     * and everybody behind them would be told they are further back than they are —
     * "you are third in line" when they are second.
     *
     * Only when they were actually waiting: renumbering on every refusal would be a
     * query per answered request for nothing.
     */
    private function closeTheirPlaceInTheQueue(SeatRequest $request, bool $wasWaiting): void
    {
        if ($wasWaiting) {
            WaitlistPositions::renumber($request->commute_offer_id);
        }
    }

    private function assertOpen(SeatRequest $request): void
    {
        if ($request->status !== SeatRequestStatus::Pending
            && $request->status !== SeatRequestStatus::Waitlisted) {
            throw DomainException::of(ErrorCode::SeatRequestNotPending);
        }
    }
}
