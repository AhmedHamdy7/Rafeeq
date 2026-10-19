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
];
