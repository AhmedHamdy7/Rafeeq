<?php

namespace App\Domains\Notification\Enums;

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

    // ---- Trip ---------------------------------------------------------------
    case TripStarted = 'trip_started';
    case ChatMessage = 'chat_message';
    case RatingDue = 'rating_due';
    case RatingDueRiders = 'rating_due_riders';
    case RatingReminder = 'rating_reminder';

    /**
     * 🔴 The driver replaced her car, and the plate the passenger was shown is no longer the car
     * that will collect her (Feature 02 v3: vehicle replacement, with confirmed bookings kept).
     *
     * `Booking` rather than `Trip`: it is news about a booking she already holds, and it has to
     * reach her the same way a seat approval does. Somebody who has muted trip chatter must still
     * learn that a different car is coming.
     */
    case BookingVehicleChanged = 'booking_vehicle_changed';

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
            self::BookingVehicleChanged,
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
