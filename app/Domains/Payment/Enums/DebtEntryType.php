<?php

namespace App\Domains\Payment\Enums;

/**
 * What one line of the debt ledger is (PAY-08, decision D-0005).
 *
 * 🔴 The ledger records what somebody OWES and what has reduced it — nothing else. There is no
 * deposit, no top-up and no balance a member can spend: e-money probably needs a Central Bank of
 * Egypt licence (open question Q-006), so a credit here is only ever a discount on something owed
 * later, never money held for somebody.
 *
 * Each case carries the sign it is allowed to have, so a caller cannot record a "payment" that
 * increases a debt or a "commission" that pays one off. `adjustment` and `fee_line` are the two
 * that may go either way, and each says why.
 */
enum DebtEntryType: string
{
    /** RAFEEQ's commission on one seat, owed by the driver from the moment she taps Dropped off (PAY-07). */
    case Commission = 'commission';

    /** A fee or penalty (no-show, late cancel, lateness, driver cancel…). Amounts and ladders: PAY-20 → PAY-32. */
    case Fee = 'fee';

    /** The +10 % added once to a week's unpaid commission when the Friday deadline passes (PAY-13). */
    case LateFee = 'late_fee';

    /** An appeal approved after the fee was paid, or a dispute that made somebody whole (PAY-10, PAY-31). */
    case Credit = 'credit';

    /** An InstaPay payment an admin confirmed (PAY-09, ADM-03). */
    case Payment = 'payment';

    /** A coupon an admin issued to the side that was harmed (PAY-34, ADM-04). */
    case Coupon = 'coupon';

    /**
     * A passenger's balance carried on her next ride and collected in cash by the driver (PAY-16).
     *
     * Written in PAIRS: minus on the passenger's side, plus on the driver's — the debt moves from
     * the person who owed it to the person now holding the cash. When the passenger had a credit
     * instead, the signs flip: her fare is discounted, and the discount comes off the driver's debt.
     */
    case FeeLine = 'fee_line';

    /** Staff correcting a figure, in either direction, always with a reason. */
    case Adjustment = 'adjustment';

    /** Staff forgiving a debt. */
    case WriteOff = 'write_off';

    /**
     * Whether a line of this type may add to what somebody owes.
     */
    public function mayIncrease(): bool
    {
        return match ($this) {
            self::Commission, self::Fee, self::LateFee, self::FeeLine, self::Adjustment => true,
            default => false,
        };
    }

    /**
     * Whether a line of this type may reduce what somebody owes.
     */
    public function mayDecrease(): bool
    {
        return match ($this) {
            self::Commission, self::Fee, self::LateFee => false,
            default => true,
        };
    }
}
