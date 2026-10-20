<?php

namespace App\Domains\Commute\Enums;

/**
 * Where a driver's proposed change to a running commute has got to (Feature 03 v5 §6).
 *
 * v5's own list is `DRAFT|PENDING|APPLIED|REJECTED|WITHDRAWN|EXPIRED`, and all six are here except
 * `DRAFT`: a proposal is created by sending it, and a draft the passengers cannot see is a screen
 * state rather than a row. Adding it would mean a status whose only behaviour is that nothing
 * happens.
 */
enum ChangeProposalStatus: string
{
    /** Sent, and waiting on the affected passengers. The original schedule is still authoritative. */
    case Pending = 'pending';

    /** Everybody affected said yes, and the change is in force. */
    case Applied = 'applied';

    /**
     * Somebody said no.
     *
     * 🔴 One refusal ends it, because v5 forbids partial application — *"Change proposal partial
     * acceptance never applies"* — so a proposal with a rejection in it can never be applied, and
     * leaving it pending would have the others waiting on an answer that cannot change anything.
     * The driver can revise it, which starts the asking over.
     */
    case Rejected = 'rejected';

    /** The driver took it back before it was applied. */
    case Withdrawn = 'withdrawn';

    /**
     * It ran out of time.
     *
     * v5: *"Proposal expires at the original locked departure time"* / *"Proposal expires at
     * affected occurrence start"*. A proposal about this morning's departure is meaningless once
     * the car has gone, and leaving it open would let a late acceptance rewrite a journey that has
     * already happened.
     */
    case Expired = 'expired';

    /** Whether the proposal can still be answered, revised or withdrawn. */
    public function isLive(): bool
    {
        return $this === self::Pending || $this === self::Rejected;
    }

    public function isTerminal(): bool
    {
        return $this === self::Applied || $this === self::Withdrawn || $this === self::Expired;
    }
}
