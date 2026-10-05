<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * The answers to a seat request that are not an approval: a refusal by the driver,
 * a withdrawal by the passenger, and the sweep that closes requests nobody
 * answered.
 *
 * Approval lives in its own Action because it is the one that touches seats under
 * a lock. These three change only the request itself.
 */
final readonly class RespondToSeatRequestAction
{
    public function __construct(private PromoteFromWaitlistAction $waitlist) {}

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
     * Different from the automatic waitlisting at request time, which happens because the
     * DAY was full. This is the driver choosing, so it works even on a day with a free
     * seat.
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
                 * The clock restarts. The 48 hours it had were for the driver to answer,
                 * and they just did — expiring it on the old deadline would drop somebody
                 * out of a queue they were only put in a moment ago.
                 */
                'expires_at' => now()->addHours((int) config('rafeeq.booking.request_expiry_hours')),
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
     * Requests nobody answered in time.
     *
     * Swept rather than judged lazily on read, because an open request holds the
     * passenger's one active slot for that commute — so leaving it open means they
     * cannot ask again while waiting for an answer that is not coming.
     *
     * @return int how many expired
     */
    public static function expireOverdue(): int
    {
        return SeatRequest::query()
            ->whereIn('status', [
                SeatRequestStatus::Pending->value,
                SeatRequestStatus::Waitlisted->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => SeatRequestStatus::Expired->value]);
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
            $this->waitlist->renumber($request->commute_offer_id);
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
