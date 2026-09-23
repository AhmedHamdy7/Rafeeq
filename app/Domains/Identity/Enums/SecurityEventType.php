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

    // ---- Verification (Phase 3) ---------------------------------------
    case DocumentRejected = 'document_rejected';
    case DocumentAccessed = 'document_accessed';
    case VerificationSubmitted = 'verification_submitted';
    case VerificationApproved = 'verification_approved';
    case VerificationRejected = 'verification_rejected';

    // ---- Driver application (Phase 4) ---------------------------------
    case DriverDuplicateDetected = 'driver_duplicate_detected';
    case DriverApplicationSubmitted = 'driver_application_submitted';
    case DriverApproved = 'driver_approved';
    case DriverRejected = 'driver_rejected';
    case DriverSuspended = 'driver_suspended';

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
            self::SuspendedAccessAttempt,
            // A refused upload is either a mistake or a probe, and the two are
            // indistinguishable at the moment it happens.
            self::DocumentRejected,
            self::DriverSuspended => SecurityRiskLevel::Medium,

            // Two people claiming the same national id or licence is the
            // signature of identity fraud, and Chapter 3 §16 says such an
            // application must never be approved automatically.
            self::DriverDuplicateDetected => SecurityRiskLevel::High,

            default => SecurityRiskLevel::Low,
        };
    }
}
