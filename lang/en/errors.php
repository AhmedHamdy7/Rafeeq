<?php

return [
    'BAD_REQUEST' => 'The request could not be processed.',
    'VALIDATION_FAILED' => 'The given data was invalid.',
    'UNAUTHENTICATED' => 'Authentication is required.',
    'FORBIDDEN' => 'You are not authorized to perform this action.',
    'NOT_FOUND' => 'The requested resource was not found.',
    'METHOD_NOT_ALLOWED' => 'This method is not allowed for this endpoint.',
    'CONFLICT' => 'This request conflicts with the current state.',
    'GONE' => 'This resource is no longer available.',
    'PAGE_EXPIRED' => 'Your session expired. Please try again.',
    'PAYLOAD_TOO_LARGE' => 'The uploaded file is too large.',
    'TOO_MANY_REQUESTS' => 'Too many requests. Please try again later.',
    'SERVER_ERROR' => 'Something went wrong. Please try again later.',
    'SERVICE_UNAVAILABLE' => 'The service is temporarily unavailable. Please try again shortly.',

    // Authentication & identity. These messages are shown to the person, so
    // they never state whether an account exists for the number.
    'AUTH_OTP_CHALLENGE_NOT_FOUND' => 'This verification request is no longer available. Please request a new code.',
    'AUTH_OTP_INVALID' => 'The code you entered is incorrect.',
    'AUTH_OTP_EXPIRED' => 'This code has expired. Please request a new one.',
    'AUTH_OTP_MAX_ATTEMPTS' => 'Too many incorrect attempts. Please request a new code.',
    'AUTH_OTP_RESEND_COOLDOWN' => 'Please wait a moment before requesting another code.',
    'AUTH_OTP_MAX_RESENDS' => 'You have requested too many codes. Please try again later.',
    'AUTH_OTP_DELIVERY_FAILED' => 'We could not send the code right now. Please try again shortly.',
    'AUTH_PHONE_INVALID' => 'Please enter a valid Egyptian mobile number.',
    'AUTH_SESSION_INVALID' => 'Your session is no longer valid. Please sign in again.',
    'AUTH_SESSION_EXPIRED' => 'Your session has expired. Please sign in again.',
    'AUTH_SESSION_REUSE_DETECTED' => 'For your security, all sessions were signed out. Please sign in again.',
    'AUTH_DEVICE_REVOKED' => 'This device no longer has access. Please sign in again.',
    'ACCOUNT_SUSPENDED' => 'Your account is currently suspended.',
    'ACCOUNT_PROFILE_INCOMPLETE' => 'Please finish setting up your profile first.',

    // Verification. These are read out loud to the person, so they say what to
    // do next rather than what went wrong internally.
    'VERIFICATION_REQUIRED' => 'Please complete verification to continue.',
    'VERIFICATION_NOT_SUBMITTABLE' => 'Some required documents are still missing or being checked.',
    'VERIFICATION_ALREADY_APPROVED' => 'This step is already verified.',
    'VERIFICATION_ATTEMPTS_EXHAUSTED' => 'You have tried this too many times. Our team will take a look.',
    'DOCUMENT_UNREADABLE' => 'We could not read that image. Please take a clear, uncropped photo and try again.',
    'DOCUMENT_REJECTED_BY_SCANNER' => 'That file could not be accepted. Please upload a photo taken with your camera.',
    'DOCUMENT_KIND_NOT_ACCEPTED' => 'That document is not part of this step.',
    'ORGANIZATION_EMAIL_MISMATCH' => 'That email does not belong to the organization you selected.',

    // Driver application. The duplicate message is deliberately vague about
    // WHICH detail matched — see the note on the error code.
    'DRIVER_NOT_ELIGIBLE' => 'You cannot apply to drive yet.',
    'DRIVER_APPLICATION_LOCKED' => 'Your application is being reviewed and cannot be changed. Withdraw it first if you need to make a correction.',
    'DRIVER_APPLICATION_INCOMPLETE' => 'Some required details or documents are still missing.',
    'DRIVER_LICENCE_EXPIRED' => 'Your licence must be valid for at least :days more days.',
    'DRIVER_DUPLICATE_DETECTED' => 'Some of these details are already registered. Please check what you entered, or contact support.',
    'VEHICLE_NOT_ALLOWED' => 'This vehicle does not meet the requirements.',
    'VEHICLE_LIMIT_REACHED' => 'You have reached the maximum number of vehicles.',

    // Commutes.
    'COMMUTE_NOT_EDITABLE' => 'This commute cannot be changed in its current state.',
    'COMMUTE_INCOMPLETE' => 'Some required details are still missing.',
    'COMMUTE_INVALID_TRANSITION' => 'That change is not possible from the commute’s current state.',
    'COMMUTE_ROUTE_INVALID' => 'Please check the route: the origin and destination must differ, and pickup points must be along the way.',
    'COMMUTE_SEATS_CONFLICT' => 'You already have more passengers booked than that number of seats.',
    'COMMUTE_VEHICLE_UNAVAILABLE' => 'That vehicle is not approved and active, so it cannot be used for a commute.',

    // Seat requests and bookings.
    'SEAT_UNAVAILABLE' => 'The seats on that day have just been taken.',
    'BOOKING_RULES_NOT_AGREED' => 'Please agree to the group rules before requesting a seat.',
    'BOOKING_ALREADY_REQUESTED' => 'You already have a request open for this commute.',
    'BOOKING_ALREADY_BOOKED' => 'You already have a seat on that day.',
    'BOOKING_DEADLINE_PASSED' => 'Bookings for that day have closed.',
    'SEAT_REQUEST_NOT_PENDING' => 'That request has already been answered.',
    'BOOKING_WAITLIST_FULL' => 'The waiting list for this commute is full.',
    'BOOKING_NOT_CANCELLABLE' => 'That booking can no longer be cancelled.',
    'BOOKING_OWN_COMMUTE' => 'You cannot book a seat on your own commute.',
    'BOOKING_RECURRING_DAYS_NOT_OFFERED' => 'This commute does not run on all of the days you asked for.',

    // Custom pickup points.
    'PICKUP_DETOUR_TOO_LONG' => 'That pickup point is further off the driver’s route than they accept.',
    'PICKUP_ALREADY_REQUESTED' => 'You already have a pickup point waiting for an answer.',
    'PICKUP_REQUEST_NOT_PENDING' => 'That pickup request has already been answered.',
    'PICKUP_NOT_ON_COMMUTE' => 'That pickup point does not belong to this commute.',

    // Groups.
    'GROUP_NOT_ACTIVE' => 'This group is not active at the moment.',
    'GROUP_NOTICE_ALREADY_GIVEN' => 'You have already given notice to leave this group.',
    'GROUP_DRIVER_CANNOT_LEAVE' => 'A driver cannot leave their own group. Pause or archive the commute instead.',
    'GROUP_ATTENDANCE_NOT_DECLARABLE' => 'It is too late to change your answer for that day.',
    'GROUP_ABSENCE_OVERLAPS' => 'You already have an absence recorded over those dates.',
    'GROUP_ABSENCE_TOO_LONG' => 'That absence is longer than a planned absence can be.',

    /*
     * The trip lifecycle. Each of these is read by a driver standing beside her car at
     * seven in the morning, so each says what happened and what to do — never the name
     * of the state that refused.
     */
    'TRIP_ALREADY_STARTED' => 'This run has already started.',
    'TRIP_NOT_STARTED' => 'This run has not started yet.',
    'TRIP_INVALID_TRANSITION' => 'That step is not possible from where this run is now.',
    'TRIP_NOT_CANCELLABLE' => 'This run can no longer be cancelled.',
    'TRIP_TOO_EARLY_TO_START' => 'It is too early to start this run. Start it closer to the departure time.',
    'ATTENDANCE_NOT_CONFIRMABLE' => 'This passenger cannot be confirmed right now.',
    'ATTENDANCE_DISPUTE_WINDOW_CLOSED' => 'The window to dispute this trip has closed. Please contact support.',
    'WAIT_TIMER_ALREADY_RUNNING' => 'A wait timer is already running for this passenger.',
    'WAIT_TIMER_NOT_RUNNING' => 'There is no wait timer running for this passenger.',

    /*
     * Admin sign-in. One message for a wrong password and a wrong code, on purpose —
     * see ErrorCode::AdminCredentialsInvalid.
     */
    'ADMIN_CREDENTIALS_INVALID' => 'Those sign-in details are not correct.',
    'ADMIN_MFA_NOT_ENROLLED' => 'Finish setting up two-factor authentication before signing in.',
    'ADMIN_MFA_REQUIRED' => 'Enter the code from your authenticator app to continue.',
];
