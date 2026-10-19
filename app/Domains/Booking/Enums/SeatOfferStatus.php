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

    /**
     * The driver took the offer back before anybody accepted it (Feature 03 v5: *"Driver may
     * withdraw only unaccepted offers"*).
     *
     * Separate from `Closed` for the same reason `TakenByOther` is: the seat still exists, and the
     * person who was offered it is entitled to know that the driver changed her mind rather than
     * that the day fell through. It is also the one closure that is somebody's decision about her,
     * which is what makes it worth being able to find in the history.
     */
    case Withdrawn = 'withdrawn';

    public function isLive(): bool
    {
        return $this === self::Open;
    }
}
