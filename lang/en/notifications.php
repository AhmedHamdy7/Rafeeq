<?php

/*
 * What the platform tells members on its own initiative (Chapter 11). Rendered in the
 * RECIPIENT's language at the moment of sending and stored as written, so the inbox reads the
 * same tomorrow even if this file changes.
 *
 * 🔒 Never a phone number, an address or a full name in these — a push body is shown on a lock
 * screen, to whoever is holding the phone.
 */
return [
    'sos_picked_up' => [
        'title' => 'Someone from Rafeeq is on it',
        'body' => 'A member of our safety team has picked up your alert.',
    ],
    'report_resolved' => [
        'title' => 'Your report has been dealt with',
        'body' => 'Open it to read what the safety team did.',
    ],
    'report_closed' => [
        'title' => 'Your report has been closed',
        'body' => 'Open it to read the safety team’s answer.',
    ],
    'account_on_hold' => [
        'title' => 'Your account is on hold',
        'body' => 'New bookings are paused while we review. Reference :case.',
    ],
    'account_reinstated' => [
        'title' => 'Your account is active again',
        'body' => 'The hold has been lifted. You can book as usual.',
    ],
    'seat_requested' => [
        'title' => 'New seat request',
        'body' => ':name would like to join your commute.',
    ],
    'seat_approved' => [
        'title' => 'You have a seat',
        'body' => ':name has approved your request.',
    ],
    'seat_declined' => [
        'title' => 'Seat request not accepted',
        'body' => ':name could not take you on this commute. Search again for another.',
    ],
    'chat_message' => [
        'title' => 'New message from :name',
        'body' => 'Open the trip to read it.',
    ],
    // Not a notification: the description of the report filed when a chat message is reported.
    'chat_report' => "Reported chat message.\nReason: :reason\nMessage: «:message»",
    'trip_day_cancelled' => [
        'title' => 'No ride on :date',
        'body' => ':name cancelled the commute for :date. Your other days are unchanged.',
    ],
    /*
     * 🔴 Leads with the fact she can act on. She is waiting somewhere, so "no car is coming" comes
     * before anything about a booking, and the second line is where to look rather than an apology.
     */
    'active_trip_cancelled' => [
        'title' => 'No car is coming',
        'body' => ':name had to stop the trip. Open it to see other commutes you can still catch.',
    ],
    'active_trip_cancelled_aboard' => [
        'title' => 'The trip was stopped',
        'body' => 'The part you rode still counts and you owe nothing. Open it to see what else runs from here.',
    ],
    'booking_cancelled' => [
        'title' => 'A seat was cancelled',
        'body' => ':name cancelled the booking for :date.',
    ],
    /*
     * 🔴 "First to accept gets it" is in the body on purpose. She is being told a race has started,
     * and a message that implied the seat was already hers would make losing it feel like it was
     * taken from her.
     */
    'seat_offered' => [
        'title' => 'A seat just opened up',
        'body' => 'A seat is free on :date. First to accept gets it.',
    ],
    /*
     * 🔴 "You are back on the waiting list" rather than "your seat is being held". v5 is explicit
     * that cancelled seats never auto-restore and that the driver chooses who to offer a seat to on
     * resume, so a message implying the seat is kept for her would be a promise nobody made.
     *
     * The day count is in the body because one decision can take a whole week off a recurring
     * rider, and she is told once — a woman who loses five mornings should not have to count the
     * notifications to find out.
     */
    /*
     * 🔒 Passive, and deliberately so: a commute is also paused automatically when the driver's
     * licence lapses or her vehicle is suspended. "She paused it" would be false in those cases,
     * and naming the real cause would tell her passengers something about her documents that is
     * none of their business. "Has been paused" is true however it happened.
     */
    'commute_paused_seat_cancelled' => [
        'title' => 'Your commute was paused',
        'body' => ':name’s commute has been paused, so :days booked day(s) were cancelled. You are back on the waiting list.',
    ],
    /*
     * 🔴 "Offered you a seat", not "gave you a seat". The driver chose who may race for these days;
     * the server still decides who wins, and she may be offered a day somebody else accepts first.
     */
    /*
     * 🔴 A question, not an answer. She has neither been given a seat nor refused one, and wording
     * that leaned either way would make her read the next screen expecting the wrong thing.
     */
    /*
     * 🔴 "Nothing changes until you answer" is in the body on purpose. v5: "If no reply, nothing
     * changes" — so a message that read like an announcement would have her believe her morning had
     * already moved, and she would plan around a departure nobody has agreed to.
     */
    'change_proposal_received' => [
        'title' => 'Your driver proposed a change',
        'body' => ':name wants to change something about your commute. Nothing changes until you review it and agree.',
    ],
    /*
     * 🔴 Sent to somebody who may already have said yes. The body has to say that her agreement no
     * longer stands, or she will plan around the version she accepted.
     */
    'change_proposal_revised' => [
        'title' => 'The proposed change was updated',
        'body' => ':name changed what she is proposing, so any answer you gave no longer counts. Please review it again.',
    ],
    'change_proposal_applied' => [
        'title' => 'The change is now in effect',
        'body' => 'Everyone affected agreed, so your commute has been updated. Check your next journey.',
    ],
    'change_proposal_rejected' => [
        'title' => 'Your proposed change was declined',
        'body' => 'One of your passengers declined, so nothing changed. You can revise it and ask again.',
    ],
    /*
     * 🔒 Not a refusal, and the wording avoids reading as one. Her application was not turned down;
     * the commute she applied for has different times now, and nobody can judge on her behalf
     * whether they still suit her.
     */
    'waitlist_cleared_by_change' => [
        'title' => 'A commute you were waiting for has changed',
        'body' => ':name updated this commute’s times, so the waiting list was reset. Take a look and apply again if it still works for you.',
    ],
    'seat_counteroffered' => [
        'title' => ':name suggested a different spot',
        'body' => ':name can take you, from a different meeting point. Open the request to see where and decide.',
    ],
    'seat_counteroffer_declined' => [
        'title' => 'Your suggested spot was declined',
        'body' => ':name would rather not meet at the point you suggested, so the request is closed.',
    ],
    'seats_offered_by_driver' => [
        'title' => 'A driver offered you a seat',
        'body' => 'You have been offered a seat on :days day(s). Accept to confirm — the first to accept each day gets it.',
    ],
    'vehicle_replaced_seat_cancelled' => [
        'title' => 'Your driver changed car',
        'body' => ':name is switching to another car, which has to be verified again, so :days booked day(s) were cancelled. You are back on the waiting list.',
    ],
    'match_found' => [
        'title' => 'A commute that fits your request',
        'body' => 'A new commute matches the trip you saved. Take a look before the seats go.',
    ],
    'rating_due' => [
        'title' => 'How was your commute today?',
        'body' => 'Rate your trip with :name. Neither of you sees the other’s rating until both have rated.',
    ],
    'rating_due_riders' => [
        'title' => 'How was your commute today?',
        'body' => 'Rate your riders. Nobody sees a rating until both sides have rated.',
    ],
    'rating_reminder' => [
        'title' => 'Last day to rate your trip',
        'body' => 'Ratings for this trip close soon.',
    ],
    'trip_started' => [
        'title' => ':name has started today’s commute',
        'body' => 'Open the trip to follow the car.',
    ],
    /*
     * 🔒 No reason, and no suggestion that she owes the driver one. It may be about the driver. The
     * message says what she needs to know to drive the rest of her run.
     */
    'passenger_ended_early' => [
        'title' => ':name has left the car',
        'body' => ':name ended her journey early. You do not need to stop for her drop-off.',
    ],
    /*
     * 🔴 Says plainly that her own lateness caused it, because that is the only version she can act
     * on — and because the alternative is a seat that empties with no explanation.
     */
    'passenger_exited_driver_delay' => [
        'title' => ':name is no longer riding today',
        'body' => ':name left because the commute had not set off. No charge to her; you do not need to stop for her.',
    ],
    /*
     * To the driver, a minute after departure. Worded as a reminder and not a warning: the
     * commonest reason a run has not started is that she is already driving and has not tapped it.
     */
    'commute_not_started_reminder' => [
        'title' => 'Your commute was due to leave',
        'body' => 'It was due to leave at :time and has not been started. Tap Start Commute so your passengers can see you are on the way.',
    ],
    /*
     * 🔴 To the passenger. It says what is true and stops there — it does NOT tell her she may
     * leave for free, because a notification announcing an entitlement reads as advice to use it.
     */
    'commute_not_started_warning' => [
        'title' => 'Your driver has not set off yet',
        'body' => ':name’s commute was due to leave at :time and has not started yet.',
    ],
    'commute_day_expired' => [
        'title' => 'Today’s commute did not run',
        'body' => ':name’s commute of :date was never started, so your seat for today has ended. You have not been charged.',
    ],
    /*
     * 🔴 v5 §10 gives this message almost word for word, including the explanation and the dispute
     * invitation: "You boarded after the 5-minute grace on Sun 18 Oct. 1st and 2nd are warnings; a
     * 3rd may lead to a fee. Tap to review or dispute."
     *
     * The explanation is not padding. A bare "warning recorded" is a punishment somebody cannot
     * place, cannot judge the seriousness of, and does not know she may answer.
     */
    'late_boarding_warning' => [
        'title' => 'Late pickup recorded (warning :ordinal)',
        'body' => 'You boarded after the 5-minute grace on :date. The 1st and 2nd are warnings; a 3rd may lead to a fee. Tap to review or dispute.',
    ],
    'missed_commitment_warning' => [
        'title' => 'Missed commute recorded (warning :ordinal)',
        'body' => 'Your seat on :date was not used and was too late to be filled. The 1st is a warning; a 2nd may lead to a fee. Tap to review or dispute.',
    ],
    'appeal_approved' => [
        'title' => 'Your appeal was approved',
        'body' => 'The record from :date no longer counts towards your reliability. It stays visible so you can see it was cleared.',
    ],
    'appeal_declined' => [
        'title' => 'Your appeal was reviewed',
        'body' => 'After review, the record from :date still stands. Contact support if you would like to discuss it.',
    ],
];
