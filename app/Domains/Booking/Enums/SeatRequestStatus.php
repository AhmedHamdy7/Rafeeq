<?php

namespace App\Domains\Booking\Enums;

enum SeatRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Waitlisted = 'waitlisted';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';

    /**
     * The approval outlived the membership it created.
     *
     * Not in the ERD's list, and added deliberately rather than by overloading one
     * that is. "One open request per passenger per commute" counts `approved` as
     * open — correctly, while somebody is riding — and the rule is enforced by a
     * generated unique column, not only by code. So when a member leaves, their
     * approved request would go on holding that slot for good, and they could never
     * ask to rejoin: a 409 with nothing they or the driver could do about it.
     *
     * `expired` would be a lie (somebody did answer) and is what the
     * unanswered-request sweep looks for; `withdrawn` would read as backing out
     * before an answer, which is not what happened either. This says what happened,
     * and being outside the generated column's status list is exactly what frees the
     * slot.
     */
    case Ended = 'ended';
}
