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
     * The driver answered with a different pickup or drop-off, and it is the passenger's turn
     * (Feature 03 v5 §2: Accept, Reject, or **Counteroffer**).
     *
     * 🔴 This counts as an OPEN request, and `seat_requests.active_request_key` was rebuilt to
     * include it. The driver has answered and is waiting; leaving it outside the generated key
     * would let the passenger file a second request on the same commute while this conversation is
     * still live, and the driver would see two of her in one inbox.
     */
    case Counteroffered = 'counteroffered';

    /**
     * The passenger refused the place the driver proposed.
     *
     * 🔴 Its own case, and the reason is whose record it lands on. `rejected` would read as the
     * driver refusing her, inflating a refusal rate for something the passenger decided;
     * `withdrawn` would read as backing out before an answer, when an answer is exactly what she
     * is responding to. Both of those histories are read by somebody deciding whether to accept
     * the other person later, so neither may absorb this.
     *
     * It closes the conversation rather than returning to `pending`: going back would show the
     * driver the same two points she had just declined, and the pair would loop.
     */
    case CounterofferDeclined = 'counteroffer_declined';

    /**
     * She was on the waiting list, and the driver changed the commute's standing terms with her
     * passengers' agreement (Feature 03 v5 §6: an Edit Entire Commute clears the whole list).
     *
     * 🔴 Its own case, because the three existing ones would each say something false. `expired` is
     * what the unanswered-request sweep writes and reads as "nobody ever got back to her";
     * `rejected` puts a refusal on the driver's record for a queue she emptied by changing her own
     * times; `withdrawn` says the passenger left. What actually happened is that the commute she
     * queued for is not the commute it is now — so she is asked to apply again, on terms she can
     * see.
     *
     * Outside the `active_request_key` list, which is what lets her reapply immediately.
     */
    case TermsChanged = 'terms_changed';

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
