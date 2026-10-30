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

    /**
     * Somebody got out of the driver's car before where she was going to set her down
     * (Feature 03 v5's passenger safety exit).
     *
     * 🔴 Filed under Trip and it reaches her mid-run, because she is driving and the car now has
     * one fewer person in it — she needs to know before she pulls up at a kerb for somebody who is
     * not there.
     *
     * 🔒 The body carries no reason. It may be about the driver, and it may be why the passenger
     * needed to get out; handing it to the person she left would make the exit something she had to
     * justify to them.
     */
    case PassengerEndedEarly = 'passenger_ended_early';

    /**
     * A passenger gave up on the journey because the driver had not set off, or had driven past her
     * stop (Feature 03 v5 §241).
     *
     * 🔴 Separate from {@see self::PassengerEndedEarly} although both say "somebody is no longer
     * travelling with you", because they are opposite news to a driver. That one is about a
     * passenger's own decision inside a journey that is going fine. This one says **the driver's own
     * lateness just cost her a passenger** — and she may still be able to save the rest of the
     * morning if she is told plainly rather than discovering an empty seat.
     *
     * Unlike that one, the reason IS carried: it is a fact about her driving, not about the
     * passenger, and a driver told she lost somebody without being told why cannot act on it.
     */
    case PassengerExitedDriverDelay = 'passenger_exited_driver_delay';

    /**
     * Her commute was due to leave and has not (Feature 03 v5 §240's `+1m reminder`).
     *
     * To the DRIVER, one minute after departure. A minute is not an accusation — it is the most
     * useful moment to be reminded, because the commonest reason a run has not started is that she
     * is driving and has not tapped anything.
     */
    case CommuteNotStartedReminder = 'commute_not_started_reminder';

    /**
     * The driver has not set off (Feature 03 v5 §240's `+5m passenger warning`).
     *
     * 🔴 To each PASSENGER, and the earliest honest moment to say it. Five minutes of standing on a
     * kerb wondering is the thing this exists to end — she can decide to keep waiting, to ask in
     * the chat, or to make another arrangement, and all three need her to know.
     *
     * ⚠️ It does not tell her she may leave for free. That becomes true at departure, and it is on
     * her booking as `driverDelay` — a notification that announced an entitlement would be read as
     * advice to use it.
     */
    case CommuteNotStartedWarning = 'commute_not_started_warning';

    /**
     * The morning was never driven (Feature 03 v5 §240's `+20m auto-EXPIRED`, and the "Commute
     * expired" state in its home-screen list).
     *
     * To each passenger holding a seat. The day is written off, nothing is charged to her, and her
     * attendance is recorded as the driver not having come — so she needs to be told plainly rather
     * than discovering it from a journey that quietly stopped being upcoming.
     */
    case CommuteDayExpired = 'commute_day_expired';

    /**
     * She kept the car waiting and then boarded (Feature 03 v5 §239, §10).
     *
     * 🔴 **Delivered after her drop-off and never during the trip**, which v5 states outright. The
     * message would otherwise reach a woman sitting in the car beside the people she kept waiting,
     * with nowhere to go for the next forty minutes.
     *
     * Carries which warning it is and whether a fee has become possible, plus the id to dispute it
     * with — a warning somebody cannot answer is a punishment with no appeal.
     */
    case LateBoardingWarning = 'late_boarding_warning';

    /**
     * She did not travel: a recorded no-show, or a cancellation too close to departure for the seat
     * to be filled (Feature 03 v5 §239's second counter).
     *
     * Told as it is recorded — there is no journey to wait for, because she is not on it.
     */
    case MissedCommitmentWarning = 'missed_commitment_warning';

    /**
     * Her appeal against a reliability event was approved (Feature 03 v5 §115).
     *
     * 🔒 The outcome only. The reviewer's note is written for the desk — it may name another
     * member, quote a driver, or record something about an investigation — so she is told what was
     * decided and support explains the rest.
     */
    case AppealApproved = 'appeal_approved';

    /** Her appeal was declined, and the event keeps counting. */
    case AppealDeclined = 'appeal_declined';

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
     * The driver answered her request with a different pickup or drop-off, and it is her turn
     * (Feature 03 v5 §2).
     *
     * 🔴 Not a `SeatApproved` and not a `SeatDeclined`, and the wording must not read as either.
     * She has neither been given a seat nor refused one — she has been asked a question, and the
     * seat is still open while she answers it.
     */
    case SeatCounteroffered = 'seat_counteroffered';

    /** The passenger refused the place the driver proposed, so the conversation is closed. */
    case SeatCounterofferDeclined = 'seat_counteroffer_declined';

    /*
     * The driver wants to change a commute people have already booked, and it cannot happen without
     * every affected passenger's yes (Feature 03 v5 §6). Four messages, because the four moments
     * are four different things to tell somebody.
     */

    /** "Your driver wants to change something. Have a look and decide." */
    case ChangeProposalReceived = 'change_proposal_received';

    /**
     * 🔴 She changed what she is asking for, so any yes already given no longer counts.
     *
     * Its own message rather than a second `received`: somebody who already agreed has to know
     * that her agreement was to the old version, or she will assume the thing she accepted is what
     * happens.
     */
    case ChangeProposalRevised = 'change_proposal_revised';

    /** Everybody agreed and the change is now in force. */
    case ChangeProposalApplied = 'change_proposal_applied';

    /** Somebody said no, so nothing changed. Goes to the driver. */
    case ChangeProposalRejected = 'change_proposal_rejected';

    /**
     * The commute she was queueing for changed its standing terms, so the waiting list was cleared
     * and she is asked to apply again.
     *
     * 🔴 Not a refusal, and the wording must not read as one. Her application was not turned down —
     * the thing she applied for is different now, and nobody can tell on her behalf whether the new
     * times still suit her.
     */
    case WaitlistClearedByChange = 'waitlist_cleared_by_change';

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
            self::SeatCounteroffered,
            self::SeatCounterofferDeclined,
            self::ChangeProposalReceived,
            self::ChangeProposalRevised,
            self::ChangeProposalApplied,
            self::ChangeProposalRejected,
            self::WaitlistClearedByChange,
            self::TripDayCancelled,
            self::MatchFound => NotificationCategory::Booking,

            self::TripStarted,
            self::PassengerEndedEarly,
            self::PassengerExitedDriverDelay,
            self::CommuteNotStartedReminder,
            self::CommuteNotStartedWarning,
            self::CommuteDayExpired,
            self::LateBoardingWarning,
            self::MissedCommitmentWarning,
            self::AppealApproved,
            self::AppealDeclined,
            self::ChatMessage,
            self::RatingDue,
            self::RatingDueRiders,
            self::RatingReminder => NotificationCategory::Trip,
        };
    }
}
