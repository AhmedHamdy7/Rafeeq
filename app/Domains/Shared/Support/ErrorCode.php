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
            self::ProfileIncomplete => 403,
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
