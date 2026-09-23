<?php

namespace App\Domains\Identity\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * The one place auth numbers come from (binding standard #11). Each getter
 * asks `platform_settings` first and falls back to the shipped default in
 * `config/rafeeq.php`, so an operator can retune OTP lifetime or session
 * length without a deploy, and nothing in the codebase carries a literal.
 */
final class AuthSettings
{
    public static function otpLength(): int
    {
        return (int) self::get('auth.otp.length');
    }

    public static function otpTtlSeconds(): int
    {
        return (int) self::get('auth.otp.ttl_seconds');
    }

    public static function otpResendCooldownSeconds(): int
    {
        return (int) self::get('auth.otp.resend_cooldown_seconds');
    }

    public static function otpMaxAttempts(): int
    {
        return (int) self::get('auth.otp.max_attempts');
    }

    public static function otpMaxResends(): int
    {
        return (int) self::get('auth.otp.max_resends');
    }

    public static function accessTtlMinutes(): int
    {
        return (int) self::get('auth.session.access_ttl_minutes');
    }

    public static function refreshTtlDays(): int
    {
        return (int) self::get('auth.session.refresh_ttl_days');
    }

    public static function pinLength(): int
    {
        return (int) self::get('auth.pin.length');
    }

    public static function otpRequestsPerPhonePerHour(): int
    {
        return (int) self::get('auth.rate_limits.otp_requests_per_phone_per_hour');
    }

    public static function otpRequestsPerIpPerHour(): int
    {
        return (int) self::get('auth.rate_limits.otp_requests_per_ip_per_hour');
    }

    public static function otpVerificationsPerChallengePerMinute(): int
    {
        return (int) self::get('auth.rate_limits.otp_verifications_per_challenge_per_minute');
    }

    /**
     * `platform_settings` wins; `config/rafeeq.php` is the shipped fallback.
     * The setting key is the config key verbatim, so the two stay traceable
     * to each other without a translation table.
     */
    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
