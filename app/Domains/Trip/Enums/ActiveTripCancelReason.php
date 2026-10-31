<?php

namespace App\Domains\Trip\Enums;

/**
 * Why a driver stopped a run that had already set off (TRIP-22, F-13).
 *
 * 🔴 A category rather than free text because what happens next depends on it: a breakdown, an
 * accident or an emergency is one fee ladder (PAY-24) and any other reason is another (PAY-25). A
 * typed sentence cannot be priced, and asking a reviewer to classify every one after the fact would
 * make the fee depend on who read it. The driver's own words still go on the record, as `note`.
 */
enum ActiveTripCancelReason: string
{
    case Breakdown = 'breakdown';
    case Accident = 'accident';
    case Emergency = 'emergency';
    case Other = 'other';

    /**
     * The three that PAY-24 treats as something the driver could not help — as opposed to PAY-25's
     * "another reason". Read by the fee rules (W3), never by anything that decides whether the
     * cancellation is allowed: every reason may stop the car.
     */
    public function isUnavoidable(): bool
    {
        return $this !== self::Other;
    }
}
