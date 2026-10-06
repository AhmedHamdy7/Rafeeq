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
