<?php

namespace App\Domains\Booking\Enums;

/**
 * What happened to one offer of a freed seat (Feature 03 v3).
 *
 * 🔴 `TakenByOther` and `Closed` are separate on purpose, and the distinction is for the person
 * reading her own history rather than for the code.
 *
 * "Somebody was quicker" and "the seat stopped existing" feel completely different to somebody who
 * was waiting a week for it, and collapsing them into one word would make the second look like the
 * first — leaving her believing she keeps losing races she never actually ran.
 */
enum SeatOfferStatus: string
{
    /** Live. She can accept it, and so can everybody else who was offered it. */
    case Open = 'open';

    /** She accepted and the seat is hers. Exactly one of a fan-out can reach this. */
    case Accepted = 'accepted';

    /** Somebody else accepted first. Not a refusal — this is what a waiting list means. */
    case TakenByOther = 'taken_by_other';

    /**
     * She stopped qualifying while it was open — she cancelled the waiting request, or her pickup
     * became unreachable once the run had started (v3: an offer closes when "pickup becomes
     * unreachable").
     */
    case Ineligible = 'ineligible';

    /** The seat stopped existing: the day was cancelled, or the driver filled it herself. */
    case Closed = 'closed';

    public function isLive(): bool
    {
        return $this === self::Open;
    }
}
