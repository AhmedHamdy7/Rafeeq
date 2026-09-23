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
];
