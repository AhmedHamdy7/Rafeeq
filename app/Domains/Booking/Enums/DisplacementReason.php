<?php

namespace App\Domains\Booking\Enums;

/**
 * Why a confirmed seat was cancelled by the platform rather than by either person on it
 * (Feature 03 v5, "Pause, resume and displaced passengers" / "Vehicle management").
 *
 * 🔴 Displacement is its own thing, not a flavour of driver cancellation. A driver who cancels one
 * person's Tuesday has made a choice about that person; a driver who pauses her commute or replaces
 * her car has made a choice about the COMMUTE, and everybody on it loses their seat as a
 * consequence. The booking still records `cancelled_by_driver` — she is the true actor, and a
 * passenger's own cancellation history must not absorb this — but the reason says which happened,
 * because the two carry different news and, when the fee policy exists, different fees.
 *
 * Each case is a reason a seat vanished with nobody at fault on the passenger's side. They are kept
 * separate rather than collapsed into one "commute unavailable" because the passenger is told a
 * different sentence for each: "she paused the commute" and "she changed her car" are not the same
 * message, and neither is a thing she can act on in the same way.
 */
enum DisplacementReason: string
{
    /** She paused the commute indefinitely. Templates and the waiting list survive; seats do not. */
    case CommutePaused = 'commute_paused';

    /**
     * She replaced her vehicle, which starts a fresh verification.
     *
     * v5: "Replace Vehicle requires new verification and cancels all future confirmed bookings
     * across that driver's commutes." Across ALL of them, because one driver has one active
     * vehicle — the replacement is not a per-commute decision.
     */
    case VehicleReplaced = 'vehicle_replaced';
}
