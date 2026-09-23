<?php

namespace App\Domains\Identity\Enums;

/**
 * The audit trail Chapter 2 §27 requires ("security events are logged").
 * Typed rather than free strings so the admin dashboard and any future
 * risk-scoring query can rely on a closed set, and so a typo cannot create
 * an event nobody ever looks for.
 */
enum SecurityEventType: string
{
    case OtpRequested = 'otp_requested';
    case OtpVerified = 'otp_verified';
    case OtpFailed = 'otp_failed';
    case OtpRateLimited = 'otp_rate_limited';
    case LoginSuccess = 'login_success';
    case AccountCreated = 'account_created';
    case SessionRefreshed = 'session_refreshed';
    case TokenReuse = 'token_reuse';
    case Logout = 'logout';
    case DeviceRevoked = 'device_revoked';
    case LocalPinSet = 'local_pin_set';
    case LocalPinReset = 'local_pin_reset';
    case SuspendedAccessAttempt = 'suspended_access_attempt';

    /**
     * The default severity for this kind of event. A caller may still raise
     * it (a failed OTP is routine; the tenth in a minute is not), but never
     * has to invent one.
     */
    public function defaultRiskLevel(): SecurityRiskLevel
    {
        return match ($this) {
            // Reuse of a rotated refresh token means a token was captured:
            // there is no benign explanation, so it is always high.
            self::TokenReuse => SecurityRiskLevel::High,

            self::OtpRateLimited,
            self::DeviceRevoked,
            self::LocalPinReset,
            self::SuspendedAccessAttempt => SecurityRiskLevel::Medium,

            default => SecurityRiskLevel::Low,
        };
    }
}
