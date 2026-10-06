<?php

namespace App\Domains\Shared\Support;

enum ErrorCode: string
{
    case BadRequest = 'BAD_REQUEST';
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case Conflict = 'CONFLICT';
    case Gone = 'GONE';
    case PageExpired = 'PAGE_EXPIRED';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case ServerError = 'SERVER_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';

    // ---- Authentication & identity (Chapter 2) -------------------------
    // Distinct codes so the app can show the right screen and the right
    // recovery affordance. None of them ever reveals whether an account
    // exists for the phone number (scenario D).
    case OtpChallengeNotFound = 'AUTH_OTP_CHALLENGE_NOT_FOUND';
    case OtpInvalid = 'AUTH_OTP_INVALID';
    case OtpExpired = 'AUTH_OTP_EXPIRED';
    case OtpMaxAttempts = 'AUTH_OTP_MAX_ATTEMPTS';
    case OtpResendCooldown = 'AUTH_OTP_RESEND_COOLDOWN';
    case OtpMaxResends = 'AUTH_OTP_MAX_RESENDS';
    case OtpDeliveryFailed = 'AUTH_OTP_DELIVERY_FAILED';
    case PhoneInvalid = 'AUTH_PHONE_INVALID';
    case SessionInvalid = 'AUTH_SESSION_INVALID';
    case SessionExpired = 'AUTH_SESSION_EXPIRED';
    case SessionReuseDetected = 'AUTH_SESSION_REUSE_DETECTED';
    case DeviceRevoked = 'AUTH_DEVICE_REVOKED';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case ProfileIncomplete = 'ACCOUNT_PROFILE_INCOMPLETE';

    // ---- Verification & documents (Phase 3) ----------------------------
    // `VerificationRequired` is the one the app acts on rather than merely
    // displays: it names the levels still missing, so the client can save the
    // intent, route to the Verification Centre, and come back to the same
    // screen afterwards.
    case VerificationRequired = 'VERIFICATION_REQUIRED';
    case VerificationNotSubmittable = 'VERIFICATION_NOT_SUBMITTABLE';
    case VerificationAlreadyApproved = 'VERIFICATION_ALREADY_APPROVED';
    case VerificationAttemptsExhausted = 'VERIFICATION_ATTEMPTS_EXHAUSTED';
    case DocumentUnreadable = 'DOCUMENT_UNREADABLE';
    case DocumentRejectedByScanner = 'DOCUMENT_REJECTED_BY_SCANNER';
    case DocumentKindNotAccepted = 'DOCUMENT_KIND_NOT_ACCEPTED';
    case DocumentDimensionsTooLarge = 'DOCUMENT_DIMENSIONS_TOO_LARGE';
    case OrganizationEmailMismatch = 'ORGANIZATION_EMAIL_MISMATCH';

    // ---- Driver & vehicles (Phase 4, Chapter 3) ------------------------
    case DriverNotEligible = 'DRIVER_NOT_ELIGIBLE';
    case DriverApplicationLocked = 'DRIVER_APPLICATION_LOCKED';
    case DriverApplicationIncomplete = 'DRIVER_APPLICATION_INCOMPLETE';
    case LicenceExpired = 'DRIVER_LICENCE_EXPIRED';
    /*
     * One code for every duplicate, whichever field it was. Telling an
     * applicant WHICH of a national id, a licence number or a plate already
     * exists would turn the endpoint into a lookup for whether a given
     * person or car is on the platform — and the honest applicant does not
     * need to know, because for them it means a mistake in what they typed.
     */
    case DriverDuplicateDetected = 'DRIVER_DUPLICATE_DETECTED';
    case VehicleNotAllowed = 'VEHICLE_NOT_ALLOWED';
    case VehicleLimitReached = 'VEHICLE_LIMIT_REACHED';

    // ---- Commutes (Phase 5, Chapter 4) ---------------------------------
    case CommuteNotEditable = 'COMMUTE_NOT_EDITABLE';
    case CommuteIncomplete = 'COMMUTE_INCOMPLETE';
    case CommuteInvalidTransition = 'COMMUTE_INVALID_TRANSITION';
    case CommuteRouteInvalid = 'COMMUTE_ROUTE_INVALID';
    case CommuteSeatsConflict = 'COMMUTE_SEATS_CONFLICT';
    case CommuteVehicleUnavailable = 'COMMUTE_VEHICLE_UNAVAILABLE';

    // ---- Seat requests & bookings (Phase 7, Chapter 6) -----------------
    case SeatUnavailable = 'SEAT_UNAVAILABLE';
    case RulesNotAgreed = 'BOOKING_RULES_NOT_AGREED';
    case AlreadyRequested = 'BOOKING_ALREADY_REQUESTED';
    case AlreadyBooked = 'BOOKING_ALREADY_BOOKED';
    case BookingDeadlinePassed = 'BOOKING_DEADLINE_PASSED';
    case SeatRequestNotPending = 'SEAT_REQUEST_NOT_PENDING';
    case WaitlistFull = 'BOOKING_WAITLIST_FULL';
    case BookingNotCancellable = 'BOOKING_NOT_CANCELLABLE';
    case CannotBookOwnCommute = 'BOOKING_OWN_COMMUTE';
    case RecurringDaysNotOffered = 'BOOKING_RECURRING_DAYS_NOT_OFFERED';

    // ---- Custom pickup points (Phase 7) --------------------------------
    case PickupDetourTooLong = 'PICKUP_DETOUR_TOO_LONG';
    case PickupAlreadyRequested = 'PICKUP_ALREADY_REQUESTED';
    case PickupRequestNotPending = 'PICKUP_REQUEST_NOT_PENDING';
    case PickupNotOnCommute = 'PICKUP_NOT_ON_COMMUTE';

    // ---- Groups (Phase 7) ----------------------------------------------
    case GroupNotActive = 'GROUP_NOT_ACTIVE';
    case GroupNoticeAlreadyGiven = 'GROUP_NOTICE_ALREADY_GIVEN';
    case GroupDriverCannotLeave = 'GROUP_DRIVER_CANNOT_LEAVE';
    case AttendanceNotDeclarable = 'GROUP_ATTENDANCE_NOT_DECLARABLE';
    case AbsenceOverlaps = 'GROUP_ABSENCE_OVERLAPS';
    case AbsenceTooLong = 'GROUP_ABSENCE_TOO_LONG';

    // ---- Trip lifecycle (Phase 9, Chapter 8) ---------------------------
    case TripAlreadyStarted = 'TRIP_ALREADY_STARTED';
    case TripNotStarted = 'TRIP_NOT_STARTED';
    case TripInvalidTransition = 'TRIP_INVALID_TRANSITION';
    case TripNotCancellable = 'TRIP_NOT_CANCELLABLE';
    case TripTooEarlyToStart = 'TRIP_TOO_EARLY_TO_START';
    case AttendanceNotConfirmable = 'ATTENDANCE_NOT_CONFIRMABLE';
    case AttendanceDisputeWindowClosed = 'ATTENDANCE_DISPUTE_WINDOW_CLOSED';
    case WaitTimerAlreadyRunning = 'WAIT_TIMER_ALREADY_RUNNING';
    case WaitTimerNotRunning = 'WAIT_TIMER_NOT_RUNNING';

    // ---- Ratings (Phase 10, Chapter 9) ---------------------------------
    case RatingWindowClosed = 'RATING_WINDOW_CLOSED';
    case RatingAlreadySubmitted = 'RATING_ALREADY_SUBMITTED';
    case RatingNotEditable = 'RATING_NOT_EDITABLE';
    case TripNotRateable = 'TRIP_NOT_RATEABLE';
    case ReviewAlreadyReported = 'REVIEW_ALREADY_REPORTED';

    // ---- Safety (Phase 11, Chapter 10) ---------------------------------
    case SosAlreadyResolved = 'SOS_ALREADY_RESOLVED';
    case EmergencyContactLimitReached = 'EMERGENCY_CONTACT_LIMIT_REACHED';
    case EmergencyContactDuplicate = 'EMERGENCY_CONTACT_DUPLICATE';
    case CannotBlockSelf = 'CANNOT_BLOCK_SELF';
    case AlreadyBlocked = 'ALREADY_BLOCKED';
    case IncidentNotReportable = 'INCIDENT_NOT_REPORTABLE';
    case IncidentEvidenceLimitReached = 'INCIDENT_EVIDENCE_LIMIT_REACHED';
    case IncidentClosed = 'INCIDENT_CLOSED';
    case LiveShareAlreadyEnded = 'LIVE_SHARE_ALREADY_ENDED';

    // ---- Notifications (Phase 12, Chapter 11) --------------------------
    case NotificationCategoryLocked = 'NOTIFICATION_CATEGORY_LOCKED';
    case ChatNotOpen = 'CHAT_NOT_OPEN';
    case ChatContactInfoNotAllowed = 'CHAT_CONTACT_INFO_NOT_ALLOWED';

    // ---- Admin dashboard (Chapter 12) ----------------------------------
    case AdminCredentialsInvalid = 'ADMIN_CREDENTIALS_INVALID';
    case AdminMfaNotEnrolled = 'ADMIN_MFA_NOT_ENROLLED';
    case AdminMfaRequired = 'ADMIN_MFA_REQUIRED';

    /*
     * Safety cases, from the operator's side (Phase 13). Only the dashboard can raise these —
     * they live with the Admin domain's Actions, outside the /v1 contract (decision D4).
     */
    case SosAlreadyAcknowledged = 'SOS_ALREADY_ACKNOWLEDGED';
    case SosNotAcknowledged = 'SOS_NOT_ACKNOWLEDGED';
    case IncidentTransitionNotAllowed = 'INCIDENT_TRANSITION_NOT_ALLOWED';
    case MemberNotActive = 'MEMBER_NOT_ACTIVE';
    case MemberNotSuspended = 'MEMBER_NOT_SUSPENDED';
    case EscortAlreadyArmed = 'ESCORT_ALREADY_ARMED';
    case EscortNotActive = 'ESCORT_NOT_ACTIVE';

    public function defaultStatus(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict => 409,
            self::Gone => 410,
            self::PageExpired => 419,
            self::PayloadTooLarge => 413,
            self::ValidationFailed => 422,
            self::TooManyRequests => 429,
            self::ServerError => 500,
            self::ServiceUnavailable => 503,

            // A wrong/expired code is a failed authentication attempt (401),
            // not a malformed request — the payload was perfectly well formed.
            self::OtpInvalid,
            self::OtpExpired,
            self::SessionInvalid,
            self::SessionExpired,
            self::SessionReuseDetected,
            self::DeviceRevoked => 401,

            self::OtpChallengeNotFound => 404,
            self::PhoneInvalid => 422,
            self::OtpMaxAttempts,
            self::OtpResendCooldown,
            self::OtpMaxResends => 429,
            self::OtpDeliveryFailed => 503,
            // 403, not 401: the caller IS authenticated, they are just not
            // allowed through. A 401 would make the app throw away a valid
            // session and loop back to the phone screen.
            self::AccountSuspended,
            self::ProfileIncomplete,
            // 403: the caller is who they say they are and is simply not
            // allowed through yet. A 401 would make the app throw away a
            // perfectly good session and start over at the phone screen.
            self::VerificationRequired => 403,

            self::VerificationAlreadyApproved => 409,
            self::VerificationAttemptsExhausted => 429,
            self::VerificationNotSubmittable,
            self::DocumentUnreadable,
            self::DocumentRejectedByScanner,
            self::DocumentKindNotAccepted,
            /*
             * 422 rather than 413. A 413 is about the number of bytes, and these bytes are fine —
             * it is the picture inside them that is too many pixels to open. The person's move is
             * to send a smaller image, and the limit is in `error.fields` so a client can say so.
             */
            self::DocumentDimensionsTooLarge,
            self::OrganizationEmailMismatch,
            self::DriverApplicationIncomplete,
            self::LicenceExpired,
            self::VehicleNotAllowed => 422,

            // 403: the account is fine, this person just may not do it yet.
            self::DriverNotEligible => 403,

            self::DriverApplicationLocked,
            self::DriverDuplicateDetected,
            self::VehicleLimitReached,
            self::CommuteNotEditable,
            self::CommuteInvalidTransition,
            // A conflict, not a validation error: the request is well formed and
            // the seat count is legal — it is the already-booked passengers that
            // make it impossible right now.
            self::CommuteSeatsConflict => 409,

            self::CommuteIncomplete,
            self::CommuteRouteInvalid,
            self::CommuteVehicleUnavailable,
            self::RulesNotAgreed,
            self::BookingDeadlinePassed => 422,

            /*
             * 409, not 422: every one of these is a well-formed request that the
             * CURRENT state refuses. The client should show what happened and
             * re-read, not ask the person to correct their input — there is
             * nothing wrong with what they sent.
             */
            self::SeatUnavailable,
            self::AlreadyRequested,
            self::AlreadyBooked,
            self::SeatRequestNotPending,
            self::WaitlistFull,
            self::BookingNotCancellable,
            self::PickupAlreadyRequested,
            self::PickupRequestNotPending,
            self::GroupNotActive,
            self::GroupNoticeAlreadyGiven,
            self::AttendanceNotDeclarable,
            self::AbsenceOverlaps => 409,

            /*
             * 422: unlike the group above, these ARE about what was sent. The
             * point proposed is too far off the driver's route, the days asked
             * for are not days this commute runs, the absence is longer than an
             * absence can be — each is fixed by changing the input.
             */
            self::PickupDetourTooLong,
            self::PickupNotOnCommute,
            self::RecurringDaysNotOffered,
            self::AbsenceTooLong => 422,

            // 403: the account is fine, this particular person may not do this.
            self::CannotBookOwnCommute,
            self::GroupDriverCannotLeave => 403,

            /*
             * 409: the same reasoning as the booking group. A driver tapping "start"
             * twice, or confirming a passenger on a run that has finished, sent a
             * perfectly good request that the run's current state refuses. There is
             * nothing for the person to correct.
             */
            self::TripAlreadyStarted,
            self::TripNotStarted,
            self::TripInvalidTransition,
            self::TripNotCancellable,
            self::AttendanceNotConfirmable,
            self::WaitTimerAlreadyRunning,
            self::WaitTimerNotRunning => 409,

            /*
             * 422 for these two, because both are about WHEN: a run started the night
             * before is a request to fix by waiting, and a dispute raised after the
             * window has closed is one the client should not have offered. Neither is
             * resolved by re-reading the state.
             */
            self::TripTooEarlyToStart,
            self::AttendanceDisputeWindowClosed => 422,

            /*
             * 409: each of these is a well-formed request the current state refuses — an SOS
             * already answered, a contact already on the list, somebody already blocked. Nothing
             * for the person to correct.
             */
            /*
             * 409: the rating already exists, or the moment for it has gone. Nothing for the
             * person to correct in what they sent — the state answers for itself.
             */
            self::RatingAlreadySubmitted,
            self::RatingWindowClosed,
            self::RatingNotEditable,
            self::ReviewAlreadyReported,

            self::SosAlreadyResolved,
            self::EmergencyContactDuplicate,
            self::AlreadyBlocked,
            self::LiveShareAlreadyEnded,
            /*
             * 409 rather than 403: the case is finished, which is a state and not a permission. The
             * client's move is to file a new report, not to retry with different credentials — and
             * that distinction is the whole reason this is not a generic Forbidden.
             */
            self::IncidentClosed => 409,

            // 422: about what was sent. The list is full, or the report has nothing to attach to.
            self::EmergencyContactLimitReached,
            self::IncidentEvidenceLimitReached,
            self::IncidentNotReportable,
            // 422: the journey itself cannot be rated — it was cancelled, or it has not happened.
            self::TripNotRateable => 422,

            // 403: blocking yourself is not a state, it is a mistake about who you are.
            self::CannotBlockSelf => 403,

            // 422: what was sent asks for something the platform does not allow — safety
            // notices cannot be switched off (Chapter 11).
            self::NotificationCategoryLocked => 422,

            // 409: the conversation's window is a state — not yet open, already closed. Also
            // what a blocked pair gets, deliberately indistinguishable (SendChatMessageAction).
            self::ChatNotOpen => 409,

            // 422: the message itself is what is refused — a phone number or an email in it.
            self::ChatContactInfoNotAllowed => 422,

            /*
             * 401: a staff sign-in that did not succeed. One code for a wrong
             * password AND a wrong TOTP code, deliberately — distinguishing them
             * tells an attacker their password was right, which is half the answer.
             */
            self::AdminCredentialsInvalid => 401,

            // 403, not 401: these two ARE authenticated as far as the password goes,
            // and are being stopped by the second factor rather than by identity.
            self::AdminMfaNotEnrolled,
            self::AdminMfaRequired => 403,

            /*
             * 409: two operators on one case, or a step taken out of order. Nothing about the
             * request is wrong — the case moved underneath the page, and the answer is to look
             * at it again.
             */
            self::SosAlreadyAcknowledged,
            self::SosNotAcknowledged,
            self::IncidentTransitionNotAllowed,
            // Somebody else already put this account on hold, or already lifted it.
            self::MemberNotActive,
            self::MemberNotSuspended,
            // The corridor is already watched, or the watch already ended.
            self::EscortAlreadyArmed,
            self::EscortNotActive => 409,
        };
    }

    public function message(): string
    {
        return __('errors.'.$this->value);
    }

    /**
     * Anything unmapped falls back by status class, never straight to
     * SERVER_ERROR: a 4xx labelled "something went wrong on our side" tells
     * the client to retry a request that will never succeed.
     */
    public static function fromHttpStatus(int $status): self
    {
        return match ($status) {
            400 => self::BadRequest,
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::Conflict,
            410 => self::Gone,
            413 => self::PayloadTooLarge,
            419 => self::PageExpired,
            422 => self::ValidationFailed,
            429 => self::TooManyRequests,
            503 => self::ServiceUnavailable,
            default => $status >= 400 && $status < 500 ? self::BadRequest : self::ServerError,
        };
    }
}
