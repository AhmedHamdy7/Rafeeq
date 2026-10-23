<?php

namespace App\Domains\Outbox\Enums;

use App\Domains\Outbox\Support\Outbox;

/**
 * The state changes this system announces to a client that is watching (Feature 03 v5).
 *
 * > *"Publish idempotent outbox events for request/counteroffer/approved, waitlist approval, seat
 * > opened/offer accepted/offer closed, booking switched, pickup arrival, late warning after trip,
 * > driver delay/expiry, proposal revision, cancellation, SOS/report. Websocket/push consumers must
 * > deduplicate event IDs."*
 *
 * 🔴 **Only the cases something actually emits are here, and v5's list is longer than this enum.**
 * The ones that are missing are named in {@see Outbox} rather than
 * declared and left silent: an event type nothing ever publishes would tell a client to listen for
 * something that never arrives, which is worse than knowing it has to poll.
 *
 * 🔴 The chosen four are the ones where a missed message breaks a screen rather than delaying it.
 * All four belong to the seat race, which is the one place in the product where two people are
 * acting on the same object at the same second and the loser has to be told something true.
 */
enum OutboxEventType: string
{
    /**
     * A seat came free and was offered to everybody waiting.
     *
     * 🔴 v5 specifies this one by name and shape — *"Seat release ... emits
     * `seat_opened(occurrence_id, seat_count, event_id)` **once via outbox**"* — and the `event_id`
     * it means is the fan-out's own `release_event_id`, which `seat_offers` already carries. The
     * same id on the event and on every offer it produced is what lets a client tie the two
     * together instead of guessing.
     */
    case SeatOpened = 'seat.opened';

    /** Somebody took it. Exactly one of a fan-out can produce this. */
    case OfferAccepted = 'offer.accepted';

    /**
     * An offer stopped being live — somebody was quicker, the day went, or the driver withdrew it.
     *
     * The payload carries WHICH, because v5 and the enum behind it both insist those are different
     * things to be told: losing a race is not the same as the seat ceasing to exist.
     */
    case OfferClosed = 'offer.closed';

    /** A confirmed seat ended. */
    case BookingCancelled = 'booking.cancelled';
}
