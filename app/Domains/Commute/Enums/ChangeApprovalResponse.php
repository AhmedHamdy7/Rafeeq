<?php

namespace App\Domains\Commute\Enums;

/**
 * One affected passenger's answer to a proposed change (Feature 03 v5 §6).
 *
 * 🔴 There is no "accepted part of it". v5: *"`Accept All Changes` / `Reject` ... No partial
 * accept"*, and *"Change proposal partial acceptance never applies"*. A proposal is one request
 * because the pieces of it only make sense together — a departure moved without the pickup moved
 * can be a journey nobody can make — so agreeing to half of it would be agreeing to something the
 * driver never offered.
 *
 * ⚠️ "No answer" is not a case here, and that is the point: an absent row means she has not
 * replied, and v5 is explicit that *"If no reply, nothing changes"*. Giving silence a value would
 * invite code that treated it as one.
 */
enum ChangeApprovalResponse: string
{
    case Accepted = 'accepted';

    case Rejected = 'rejected';

    /**
     * She accepted and then took it back, before it was applied.
     *
     * v5 allows exactly that — *"Passenger can withdraw acceptance until applied"* — and lists it
     * on the driver's view as its own per-passenger state. Kept distinct from `rejected` because
     * they are different things to read: one is "no", the other is "I had said yes and something
     * changed", and a driver deciding whether to revise or to cancel reads them differently.
     */
    case Withdrawn = 'withdrawn';

    /** Whether this answer counts towards unanimity. */
    public function isYes(): bool
    {
        return $this === self::Accepted;
    }
}
