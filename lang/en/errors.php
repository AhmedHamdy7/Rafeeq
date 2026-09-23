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
];
