<?php

namespace App\Http\Resources;

use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Identity\Models\User;

/**
 * One card on the driver's waiting list, for the screen where she chooses who to offer a seat to
 * (Feature 03 v5).
 *
 * v5: *"Waitlist cards show availability vs other confirmed commute, recurring vs one-day intent,
 * and **Previous Passenger** label."* Those three are the whole reason this is not just
 * {@see SeatRequestResource} — the driver is making a choice between people here, and each signal
 * is one she asked for to make it with.
 *
 * 🔒 What the extra signals are NOT allowed to be. `hasOtherConfirmedCommute` is a bare boolean: it
 * says this passenger already has a confirmed ride somewhere, and nothing about whose, where or
 * when. A driver choosing between two waiting women has a legitimate interest in knowing one of
 * them may already be covered; she has no interest whatever in which of her competitors is carrying
 * her, and the shape of this field is what keeps the second impossible.
 *
 * Built as a static array rather than a resource instance for the same reason {@see PersonSummary}
 * is: the two booleans are computed once for the whole page, in one query each, and a resource that
 * fetched them per card would be a hidden query per waiting person.
 */
final class WaitlistMemberResource
{
    /**
     * @param  bool  $isPreviousPassenger  she has held a seat on THIS commute before
     * @param  bool  $hasOtherConfirmedCommute  she holds a confirmed future seat on another commute
     * @return array<string, mixed>
     */
    public static function for(
        SeatRequest $member,
        ?User $viewer,
        bool $isPreviousPassenger,
        bool $hasOtherConfirmedCommute,
    ): array {
        return [
            // Cast, so the published contract says `string` rather than leaving the mobile team a
            // `mixed` they cannot generate a client from: `getKey()` is untyped to Scramble.
            'seatRequestId' => (string) $member->getKey(),
            'commuteId' => $member->commute_offer_id,

            // Recurring, or one day. v5 asks for the intent on the card because they are different
            // offers: a recurring member is being invited back into the group, a trial to one
            // morning.
            'commitment' => $member->commitment->value,
            'requestedDaysMask' => $member->requested_days_mask,
            // Set for a trial, which wants one specific day and no other.
            'tripId' => $member->scheduled_trip_id,
            'seats' => $member->seats,

            'waitlistPosition' => $member->waitlist_position,
            'joinedWaitlistAt' => $member->created_at->toIso8601String(),

            /*
             * 🔴 The **Previous Passenger** label. v5 lets the driver "prioritize
             * previous/recurring passengers", and she cannot do that unless the card says which.
             *
             * True for somebody who has held a seat in this car before — including a woman the
             * platform displaced when the commute was paused or the vehicle replaced, which is the
             * case this screen exists to put right.
             */
            'isPreviousPassenger' => $isPreviousPassenger,

            /*
             * Why she is on the list, when the platform put her there rather than the driver.
             * Null for somebody who joined by asking and waiting.
             */
            'displacedAt' => $member->displaced_at?->toIso8601String(),
            'displacedReason' => $member->displaced_reason,

            /*
             * 🔴 A signal, not a conflict check. It means "she has a confirmed future seat on some
             * other commute", not "she is busy on the days you are about to offer". Working out a
             * true per-day clash would need her whole timetable, which is not this driver's to
             * read, and would still be stale by the time she taps Offer.
             *
             * Nothing is blocked on it: v5 lets a passenger with another confirmed ride accept a
             * preferred driver's offer through an atomic switch. This is here so the driver is not
             * surprised when somebody declines.
             */
            'hasOtherConfirmedCommute' => $hasOtherConfirmedCommute,

            'introMessage' => $member->intro_message,
            'meetingPreference' => $member->meeting_preference->value,
            'paymentType' => $member->payment_type->value,

            // The same restraint as the seat-request inbox: a public first name, a trust level and
            // what has been verified. Never a full name, a phone or a gender.
            'passenger' => $member->passenger === null
                ? null
                : PersonSummary::for($member->passenger, $viewer),
        ];
    }
}
