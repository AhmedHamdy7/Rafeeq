<?php

namespace App\Domains\Trip\Enums;

/**
 * The things a member gets warned about, and how far each one escalates
 * (Feature 03 v5 §239).
 *
 * > *"1st warning, 2nd warning, 3rd fee eligible ... Avoid confusing with the distinct
 * > no-show/late-cancel counter (first warning, second fee)."*
 *
 * 🔴 v5 asks for these two to be kept apart by name, and the reason is in the numbers: being late
 * gets two warnings before a fee becomes possible, missing the journey altogether gets one. They
 * are not degrees of the same thing. Somebody who keeps a car waiting three minutes has
 * inconvenienced four people; somebody who never comes has made the journey pointless for them,
 * and the driver drove to a kerb for nobody.
 *
 * Deliberately a bare enum: binding standard #12 keeps every domain `Enums` namespace free of
 * Laravel, so the policy numbers below are the only thing here and the rules that act on them live
 * in the Actions.
 */
enum WarningKind: string
{
    /**
     * She made the car wait and then got in (v5: `PICKED_UP`, **not** no-show).
     *
     * Detected from a wait timer having been running when she arrived — not from the clock, and not
     * from the driver's judgement. See `ConfirmAttendanceAction::arrivalStatus()`.
     */
    case LateBoarding = 'late_boarding';

    /**
     * The old shared counter for a no-show OR a late cancellation. Kept so history reads, never
     * written any more: decision B4 split it in two, each with its own ladder and its own 90 days.
     */
    case MissedCommitment = 'missed_commitment';

    /** The driver recorded her as not there (PAY-20). Its own counter. */
    case NoShow = 'no_show';

    /** She cancelled within 12 hours of departure (PAY-21). Its own counter. */
    case LateCancel = 'late_cancel';

    /**
     * How many warnings come before a fee becomes possible.
     *
     * The amount past it is the ladder in `PassengerFees` (PAY-20 → PAY-22).
     */
    public function warningsBeforeFee(): int
    {
        return match ($this) {
            self::LateBoarding => 2,
            self::MissedCommitment, self::NoShow, self::LateCancel => 1,
        };
    }

    /**
     * Is the warning at this position the one where a fee becomes possible?
     */
    public function feeBecomesPossibleAt(int $ordinal): bool
    {
        return $ordinal > $this->warningsBeforeFee();
    }

    /**
     * Whether a warning of this kind waits for her journey to end before she is told.
     *
     * 🔴 v5 §10: *"do **not** show any lateness message during the trip. After drop-off the
     * passenger receives a push-style notification."* Telling somebody she kept the car waiting
     * while she is sitting in that car, beside the people she kept waiting, is a thing a product
     * does exactly once.
     *
     * A missed commitment has no journey to wait for — she is not in the car — so it is told as it
     * is recorded.
     */
    public function waitsForDropOff(): bool
    {
        return $this === self::LateBoarding;
    }
}
