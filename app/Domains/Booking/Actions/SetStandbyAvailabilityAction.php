<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\StandbyAvailability;
use App\Domains\Booking\Support\WaitlistEligibility;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

/**
 * "I can't ride today" on a waiting-list membership (Feature 03 v5 §8).
 *
 * > *"`Available Today` / `Not Available Today` on standby management; reversible repeatedly.
 * > Turning unavailable excludes the member from today's automatic offers, preserves future
 * > recurring membership; returning available doesn't restore closed offers. No penalty."*
 *
 * Four properties, and each one is a thing this must NOT do:
 *
 *   - **it preserves the membership.** Only one column moves. She keeps her place, the driver's
 *     approval of her and every future day she is waiting for.
 *   - **it is reversible repeatedly.** No cooldown, no record of how often she changed her mind.
 *   - **there is no penalty.** Nothing is written to `reliability_events`, and nothing should ever
 *     be: she is declining to be offered a seat she never held, which is the opposite of letting
 *     somebody down. A woman who thinks saying "not today" costs her something simply stops saying
 *     it, and then the fan-out spends its seats on people who cannot take them.
 *   - **returning available restores nothing.** Offers already closed stay closed; see below.
 */
final readonly class SetStandbyAvailabilityAction
{
    public function execute(SeatRequest $request, bool $available): SeatRequest
    {
        /*
         * 🔴 Only on a membership. A request still waiting on the driver, or one already seated, has
         * nothing for this switch to govern — {@see WaitlistEligibility} only ever
         * considers waitlisted rows — and silently accepting it would store a date that changed
         * nothing while the screen showed her as excluded.
         */
        if ($request->status !== SeatRequestStatus::Waitlisted) {
            throw DomainException::of(ErrorCode::SeatRequestNotWaitlisted, fields: [
                'status' => [$request->status->value],
            ]);
        }

        /*
         * The date is the server's, in the commute's own timezone — never the client's. See
         * {@see StandbyAvailability} on why a date and not the flag v5's sketch suggests.
         *
         * ⚠️ And note what is deliberately missing on the way back in: any attempt to reopen offers
         * that closed while she was out. v5 says returning available "doesn't restore closed
         * offers", and the reason is that those seats are gone — somebody else accepted them.
         * Reopening a closed offer would show her a seat that belongs to another woman.
         */
        $request->forceFill([
            'unavailable_on' => $available ? null : StandbyAvailability::todayFor($request)->toDateString(),
        ])->save();

        return $request;
    }
}
