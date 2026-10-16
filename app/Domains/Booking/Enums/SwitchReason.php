<?php

namespace App\Domains\Booking\Enums;

/**
 * Why a passenger moved from one commute to another (Feature 03 v3).
 *
 * 🔴 The two cases differ in exactly one way that matters to her: whether she pays to leave.
 *
 * v3 is explicit — "`VOLUNTARY_SWITCH` follows normal cancellation policy; `DRIVER_DELAY_SWITCH`
 * and driver-fault exit carry **no passenger cancellation fee**." So this enum is not a label for
 * analytics; it decides money, which is why it is a closed set rather than a free-text reason.
 *
 * Deliberately a bare enum: binding standard #12 keeps every domain `Enums` namespace free of
 * Laravel, so the rules that read it live in the Actions.
 */
enum SwitchReason: string
{
    /**
     * She found something she prefers. Her choice, so the normal cancellation policy applies to the
     * seat she is giving up — the driver loses a passenger at the same notice as any cancellation.
     */
    case Voluntary = 'voluntary';

    /**
     * The driver was late, or otherwise caused it. **No fee**, and the old booking is recorded as
     * cancelled by the DRIVER rather than by her — because that is what happened, and because the
     * difference is what keeps it off her own reliability record.
     */
    case DriverDelay = 'driver_delay';

    /**
     * Whether the person switching should be charged for the seat she is leaving.
     *
     * Asked as a question about HER rather than as a fee amount, so the policy number stays in one
     * place (`booking.cancellation_fee_piastres`) and this stays the rule about who it applies to.
     */
    public function chargesPassenger(): bool
    {
        return $this === self::Voluntary;
    }

    /**
     * Who the old booking records as having cancelled it.
     *
     * 🔴 A driver-delay switch is `cancelled_by_driver`, and that is not bookkeeping convenience:
     * it is the true actor, it is what makes the fee zero without a special case, and it is what
     * stops a driver's lateness appearing in a passenger's cancellation history.
     */
    public function cancelledByDriver(): bool
    {
        return $this === self::DriverDelay;
    }
}
