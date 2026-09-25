<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

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

        return $request;
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
