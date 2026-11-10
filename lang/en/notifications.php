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
    'waitlist_application_received' => [
        'title' => 'New waiting-list request',
        'body' => ':name would like to join your waiting list.',
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
    'commute_deleted_seat_cancelled' => [
        'title' => 'Your commute was cancelled',
        'body' => ':name has deleted this commute, so :days booked day(s) were cancelled. Search for another commute on your route.',
    ],
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
    'pickup_time_changed' => [
        'title' => 'Your pickup time changed',
        'body' => 'Your pickup on :date is now at :time.',
    ],
    'seat_approved_partly' => [
        'title' => 'You have seats on :seated days',
        'body' => 'Confirmed on :seated days. There was no space on :days, so we added you to the waiting list for them. We\'ll tell you if a seat opens up.',
    ],
    'vehicle_registration_expired' => [
        'title' => 'Your car registration has expired',
        'body' => 'Your commutes are paused and your bookings were cancelled. Upload the new registration, and you can resume once we approve it.',
    ],
    'vehicle_suspended' => [
        'title' => 'Your car was suspended',
        'body' => 'Your commutes are paused after we reviewed a report about your car. Contact support to find out what is needed.',
    ],
    'vehicle_reinstated' => [
        'title' => 'Your car is approved again',
        'body' => 'You can resume your commutes now. Cancelled bookings do not come back automatically.',
    ],
    'pickup_moved_earlier' => [
        'title' => 'Your pickup is earlier now',
        'body' => 'Your pickup on :date is now at :time.',
    ],
    'commute_archived' => [
        'title' => 'Your commute has ended',
        'body' => 'Your commute :from → :to has ended and was archived. You can duplicate it and publish it again.',
    ],
    'waitlisted_commute_ended' => [
        'title' => 'A commute you were waiting for ended',
        'body' => 'The commute :from → :to you were on the waiting list for has ended.',
    ],
    'departure_time_changed' => [
        'title' => 'Your departure time changed',
        'body' => 'Riders changed on :date, so leave at :time to arrive on time.',
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
    'seat_replacement_accepted' => [
        'title' => 'Seat replaced',
        'body' => ':name took the seat on :date. The passenger you replaced was told.',
    ],
    'seat_replacement_declined' => [
        'title' => 'Your offer was declined',
        'body' => 'Your offer for :date was declined. Your passenger keeps the seat, and nothing is charged.',
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
    'no_show_fee' => [
        'title' => 'No-show fee: EGP :amount',
        'body' => 'You were recorded as not there on :date. EGP :amount is added to your next ride. Disagree? Appeal within :hours hours.',
    ],
    'late_boarding_fee' => [
        'title' => 'Late pickup fee: EGP :amount',
        'body' => 'You boarded after the 5-minute grace on :date. EGP :amount is added to your next ride. Disagree? Appeal within :hours hours.',
    ],
    'late_cancel_fee' => [
        'title' => 'Late cancellation: EGP :amount unless your seat is taken',
        'body' => 'You cancelled :date within 12 hours of departure. If somebody from the waiting list takes your seat, it costs nothing. Appeal within :hours hours.',
    ],
    'late_cancel_fee_charged' => [
        'title' => 'Late cancellation fee: EGP :amount',
        'body' => 'Nobody took your seat on :date, so EGP :amount is added to your next ride. Disagree? Appeal within :hours hours.',
    ],
    'late_cancel_fee_waived' => [
        'title' => 'No fee for your cancellation',
        'body' => 'Somebody from the waiting list took your seat on :date, so your late cancellation costs nothing.',
    ],
    'driver_cancel_fee' => [
        'title' => 'Cancellation fee: EGP :amount',
        'body' => 'Cancelling confirmed seats close to departure adds EGP :amount to this week’s commission. Had an emergency? Appeal with evidence within :hours hours.',
    ],
    'driver_ended_trip_fee' => [
        'title' => 'Trip ended early: EGP :amount',
        'body' => 'Ending a run that had set off adds EGP :amount to this week’s commission. Breakdown or accident? Appeal with evidence within :hours hours.',
    ],
    'driver_no_show_fee' => [
        'title' => 'Missed trip: EGP :amount',
        'body' => 'Your run never started, so EGP :amount is added to this week’s commission. Appeal with evidence within :hours hours.',
    ],
    'driver_late_fee' => [
        'title' => 'Late start: EGP :amount',
        'body' => 'A passenger left because you were late, so EGP :amount is added to this week’s commission. Appeal within :hours hours.',
    ],
    'early_drop_check' => [
        'title' => 'Did you ask to get off here?',
        'body' => 'You were set down :distance km before your drop-off. Tell us if you asked for it — if you did not, you pay nothing for this ride.',
    ],
    'appeal_approved' => [
        'title' => 'Your appeal was approved',
        'body' => 'The record from :date no longer counts towards your reliability. It stays visible so you can see it was cleared.',
    ],
    'appeal_declined' => [
        'title' => 'Your appeal was reviewed',
        'body' => 'After review, the record from :date still stands. Contact support if you would like to discuss it.',
    ],
    'debt_due_tomorrow' => [
        'title' => 'EGP :amount due tomorrow',
        'body' => 'Your commission for this week is due by Friday 23:59. Pay with InstaPay from the Payments screen.',
    ],
    'debt_overdue' => [
        'title' => 'EGP :amount is overdue',
        'body' => 'Pay with InstaPay to publish commutes and accept new riders again. Your booked rides continue.',
    ],
    'debt_late_fee_added' => [
        'title' => 'Commission overdue: EGP :fee late fee added',
        'body' => 'You now owe EGP :amount. Until it is paid you cannot publish commutes or accept new riders.',
    ],
    'debt_suspension_warning' => [
        'title' => 'Pay EGP :amount by Friday to keep driving',
        'body' => 'If it is still unpaid after Friday 23:59, your upcoming trips will be cancelled and driving suspended.',
    ],
    'debt_payment_confirmed' => [
        'title' => 'Payment received',
        'body' => 'EGP :amount has been taken off what you owe. Thank you.',
    ],
    'debt_payment_rejected' => [
        'title' => 'We could not find your payment',
        'body' => 'Check the InstaPay reference and send it again, or contact support.',
    ],
    'coupon_issued' => [
        'title' => 'You have a coupon',
        'body' => 'EGP :amount off what you owe next, for a ride that did not go as it should.',
    ],
    'driving_suspended_for_debt' => [
        'title' => 'Driving suspended',
        'body' => 'Your commission was not paid by the second Friday, so your upcoming trips were cancelled. Pay EGP :amount to drive again. You can still ride as a passenger.',
    ],
    'riding_suspended_for_debt' => [
        'title' => 'Riding suspended',
        'body' => 'A fee has stayed unpaid, so your upcoming trips were cancelled and booking is paused. Pay EGP :amount to ride again, or appeal from the app.',
    ],

    /*
     * Feature 03 files §8 — the pushes that were missing (W6). Each deep-links through `data`.
     */
    'trip_starts_in_hour' => [
        'title' => 'Your commute leaves in about an hour',
        'body' => 'Departure is at :time. Open the trip for the details.',
    ],
    'trip_starts_soon' => [
        'title' => 'Your commute leaves in :minutes minutes',
        'body' => 'Departure is at :time.',
    ],
    'driver_heading_to_you' => [
        'title' => ':name is on the way to you',
        'body' => 'You are the next pickup. Be ready at your meeting point.',
    ],
    'driver_arrived' => [
        'title' => ':name has arrived',
        'body' => 'Your driver is at your meeting point.',
    ],
    'commute_still_not_started' => [
        'title' => 'Your driver is :minutes minutes late',
        'body' => ':name’s commute was due to leave at :time and still has not started.',
    ],
    'driver_verified' => [
        'title' => 'You are a verified driver',
        'body' => 'Your documents were approved. You can publish your commute now.',
    ],
    'driver_item_approved' => [
        'title' => ':item approved',
        'body' => 'Your :item was approved.',
    ],
    'driver_item_rejected' => [
        'title' => ':item needs a new upload',
        'body' => 'Open the app to see why, and replace only this item.',
    ],
    // The names of the five items, as they read in the two notices above.
    'driver_items' => [
        'government_id' => 'National ID',
        'selfie' => 'Selfie',
        'driving_licence' => 'Driving licence',
        'vehicle_registration' => 'Car registration',
        'vehicle_details' => 'Car details',
    ],
    'driver_documents_rejected' => [
        'title' => 'Your documents need a change',
        'body' => 'Open the app to see what to fix and send it again.',
    ],
    'driver_item_expiring' => [
        'title' => ':item expires soon',
        'body' => 'It expires on :date. Upload the renewed one to keep driving.',
    ],
    'driver_licence_expired' => [
        'title' => 'Your driving licence has expired',
        'body' => 'Your commutes are paused and upcoming trips are cancelled. Send your renewed licence for review to drive again.',
    ],
    'driver_suspended' => [
        'title' => 'Your driving is suspended',
        'body' => 'Your commutes are paused and upcoming trips are cancelled, with no fee. You can still ride as a passenger. Contact support.',
    ],
    'pickup_change_requested' => [
        'title' => ':name asks for a new pickup',
        'body' => ':name asks to be picked up somewhere else, :minutes min more for you. Open it to accept or decline.',
    ],
    'pickup_change_approved' => [
        'title' => ':name accepted your new pickup',
        'body' => 'From your next trip, :name picks you up at the new spot.',
    ],
    'pickup_change_alternative' => [
        'title' => ':name suggested another spot',
        'body' => ':name can pick you up at :place instead. Open it to accept or keep your pickup.',
    ],
    'pickup_change_rejected' => [
        'title' => ':name kept your pickup',
        'body' => ':name can\'t pick you up at the new spot. Your pickup stays the same.',
    ],
    'pickup_change_alternative_accepted' => [
        'title' => ':name accepted your spot',
        'body' => 'From the next trip, you pick :name up at :place.',
    ],
    'pickup_change_alternative_declined' => [
        'title' => ':name kept their pickup',
        'body' => ':name prefers their current pickup to :place. Nothing changes.',
    ],
];
