<?php

namespace App\Domains\Shared\Support;

/**
 * Whether the platform's request limits apply — the OTP request/resend/verify limits, search, chat
 * and reports.
 *
 * `RAFEEQ_RATE_LIMITS=off` switches them off for a test environment, where a team signing in fifty
 * times an hour would otherwise lock itself out. 🔒 It is IGNORED on APP_ENV=production, checked
 * against the environment rather than trusted from config — the same guard as `RAFEEQ_DEV_OTP_CODE`,
 * for the same reason: config is exactly what a wrong deploy gets wrong, and without these limits
 * anybody could make the platform send unlimited SMS or try login codes as fast as they like.
 */
final class RateLimits
{
    public static function enabled(): bool
    {
        return app()->isProduction() || config('rafeeq.rate_limits_enabled') !== false;
    }
}
