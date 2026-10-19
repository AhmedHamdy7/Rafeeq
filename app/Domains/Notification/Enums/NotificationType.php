<?php

namespace App\Domains\Notification\Enums;

use App\Domains\Notification\Support\Notifier;

/**
 * Everything the platform tells a member on its own initiative (Chapter 11).
 *
 * One case per message, each owning its category — and the category decides whether the
 * member may turn it off. Chapter 11: "Safety alerts (cannot be fully disabled)". An account
 * being put on hold is filed under safety for the same reason: a member must never miss being
 * told their access changed, whatever they switched off.
 *
 * The wording lives in `lang/{en,ar}/notifications.php` under the case's value; the client
 * routes on `type` and reads `data`, and shows `title`/`body` as written.
 */
enum NotificationType: string
{
    // ---- Safety (never fully off) -----------------------------------------
    case SosPickedUp = 'sos_picked_up';
    case ReportResolved = 'report_resolved';
    case ReportClosed = 'report_closed';
    case AccountOnHold = 'account_on_hold';
    case AccountReinstated = 'account_reinstated';

    // ---- Booking ------------------------------------------------------------
    case SeatRequested = 'seat_requested';
    case SeatApproved = 'seat_approved';
    case SeatDeclined = 'seat_declined';
    case BookingCancelled = 'booking_cancelled';
    case TripDayCancelled = 'trip_day_cancelled';
    case MatchFound = 'match_found';

    /*
     * There is no `booking_vehicle_changed`. It existed while replacing a vehicle kept the
     * confirmed bookings and merely told each passenger the new plate; Feature 03 v5 cancels those
     * bookings instead, so nobody is left holding a seat to be told about. The two cases below
     * carry the news now.
     */

    // ---- Trip ---------------------------------------------------------------
    case TripStarted = 'trip_started';
    case ChatMessage = 'chat_message';
    case RatingDue = 'rating_due';
    case RatingDueRiders = 'rating_due_riders';
    case RatingReminder = 'rating_reminder';

    /**
     * A seat she was waiting for came free and is being offered to her — and to everybody else on
     * the list at the same time (Feature 03 v3: first acceptance wins).
     *
     * 🔴 The wording must not promise her the seat. She is being told a race has started, and the
     * notification is the starting gun rather than the prize.
     */
    case SeatOffered = 'seat_offered';

    /**
     * Her confirmed seat was cancelled because the driver paused the whole commute, and she is back
     * on the waiting list (Feature 03 v5: "displaced passengers are notified and automatically
     * added to preserved waiting list").
     *
     * 🔴 The wording must not suggest the seat is waiting for her. v5: "Canceled seats never
     * auto-restore" — on resume the driver chooses who to offer a seat to, so being on the list is
     * a place in a queue and nothing more.
     */
    case CommutePausedSeatCancelled = 'commute_paused_seat_cancelled';

    /**
     * The driver picked her off the waiting list and offered her seats (Feature 03 v5's explicit
     * exception to the automatic fan-out, used when a commute resumes).
     *
     * 🔴 Separate from {@see self::SeatOffered} even though both announce an offer, because they
     * are different news. That one says a seat came free and a race has started. This one says a
     * particular driver chose HER, for a set of days — which is what a woman who lost her commute
     * to a pause has been waiting to hear. Collapsing them would make the second read like a
     * lottery ticket.
     *
     * It still promises nothing: the first acceptance committed by the server wins, as always.
     */
    case SeatsOfferedByDriver = 'seats_offered_by_driver';

    /**
     * The same news, for the other reason it happens: the driver replaced her car, which starts a
     * fresh verification and cancels every future confirmed seat she had sold.
     *
     * Kept separate from the paused case rather than sharing one message with the reason
     * substituted in, because {@see Notifier} renders the wording
     * in the RECIPIENT's language — a reason phrase translated by the caller would arrive in the
     * caller's language inside the passenger's sentence.
     */
    case VehicleReplacedSeatCancelled = 'vehicle_replaced_seat_cancelled';

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::SosPickedUp,
            self::ReportResolved,
            self::ReportClosed,
            self::AccountOnHold,
            self::AccountReinstated => NotificationCategory::Safety,

            self::SeatRequested,
            self::SeatApproved,
            self::SeatDeclined,
            self::BookingCancelled,
            self::SeatOffered,
            self::CommutePausedSeatCancelled,
            self::VehicleReplacedSeatCancelled,
            self::SeatsOfferedByDriver,
            self::TripDayCancelled,
            self::MatchFound => NotificationCategory::Booking,

            self::TripStarted,
            self::ChatMessage,
            self::RatingDue,
            self::RatingDueRiders,
            self::RatingReminder => NotificationCategory::Trip,
        };
    }
}
