<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Enums\OtpStatus;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Support\AuthSettings;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Issues a one-time code for a phone number.
 *
 * The single most important property of this Action is what it does NOT do:
 * it never looks at the `users` table. There is no branch, no extra query,
 * and no different response for a number that has an account versus one
 * that does not — which is what makes the identical reply in scenario D
 * true rather than merely intended. Account state is decided only after the
 * caller proves control of the phone, in VerifyOtpAction.
 */
final readonly class RequestOtpAction
{
    public function __construct(private OtpSender $sender) {}

    /**
     * @return array{challenge: OtpChallenge, expiresInSeconds: int, resendAvailableInSeconds: int, maskedPhone: string}
     */
    public function execute(
        PhoneNumber $phone,
        OtpPurpose $purpose,
        ?string $ip = null,
        ?string $devicePublicId = null,
    ): array {
        $this->assertWithinRateLimits($phone, $ip);

        $previous = $this->activeChallengeFor($phone, $purpose);

        if ($previous !== null) {
            $this->assertResendAllowed($previous);
        }

        // Scenario E: a late-arriving SMS must not work. Every earlier
        // pending challenge for this phone+purpose dies the moment a new one
        // is issued, so exactly one code is ever live.
        OtpChallenge::query()
            ->where('phone_e164', $phone->e164)
            ->where('purpose', $purpose->value)
            ->where('status', OtpStatus::Pending->value)
            ->update(['status' => OtpStatus::Expired->value]);

        $code = $this->generateCode();

        $challenge = OtpChallenge::create([
            'phone_e164' => $phone->e164,
            'purpose' => $purpose->value,
            // Hashed, never stored or returned in the clear — the response
            // carries a challenge id only (Chapter 2 §23.1).
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds(AuthSettings::otpTtlSeconds()),
            'max_attempts' => AuthSettings::otpMaxAttempts(),
            'device_fingerprint_hash' => $devicePublicId === null ? null : hash('sha256', $devicePublicId),
            'ip_hash' => SecurityLog::hashIp($ip),
        ]);

        // `resend_count` is carried across the chain, not reset per row:
        // otherwise "request a new code" would reset the abuse counter and
        // the cap would never be reached.
        $challenge->forceFill(['resend_count' => ($previous?->resend_count ?? -1) + 1])->save();

        $this->deliver($phone, $code, $purpose, $challenge);

        SecurityLog::record(SecurityEventType::OtpRequested, metadata: [
            'purpose' => $purpose->value,
            'phone' => SecurityLog::maskPhone($phone),
            'resend_count' => $challenge->resend_count,
        ]);

        return [
            'challenge' => $challenge,
            'expiresInSeconds' => AuthSettings::otpTtlSeconds(),
            'resendAvailableInSeconds' => AuthSettings::otpResendCooldownSeconds(),
            'maskedPhone' => $phone->masked(),
        ];
    }

    /**
     * Two independent budgets: one protects a person from SMS harassment,
     * the other stops one source from spraying many numbers. Both are
     * consumed before delivery, so a caller cannot dodge the limit by
     * causing the provider to fail.
     */
    private function assertWithinRateLimits(PhoneNumber $phone, ?string $ip): void
    {
        $phoneKey = 'otp:phone:'.hash('sha256', $phone->e164);

        if (RateLimiter::tooManyAttempts($phoneKey, AuthSettings::otpRequestsPerPhonePerHour())) {
            $this->refuseRateLimited($phoneKey, $phone, 'phone');
        }

        $ipKey = $ip === null ? null : 'otp:ip:'.SecurityLog::hashIp($ip);

        if ($ipKey !== null && RateLimiter::tooManyAttempts($ipKey, AuthSettings::otpRequestsPerIpPerHour())) {
            $this->refuseRateLimited($ipKey, $phone, 'ip');
        }

        RateLimiter::hit($phoneKey, 3600);

        if ($ipKey !== null) {
            RateLimiter::hit($ipKey, 3600);
        }
    }

    private function refuseRateLimited(string $key, PhoneNumber $phone, string $axis): never
    {
        SecurityLog::record(SecurityEventType::OtpRateLimited, metadata: [
            'axis' => $axis,
            'phone' => SecurityLog::maskPhone($phone),
        ]);

        throw DomainException::retryAfter(
            ErrorCode::TooManyRequests,
            RateLimiter::availableIn($key),
        );
    }

    /**
     * Chapter 2 §12: a resend cooldown and a hard cap on resends. The
     * cooldown is measured from when the live code was issued, so holding
     * the button down cannot spray messages.
     */
    private function assertResendAllowed(OtpChallenge $previous): void
    {
        $cooldownEndsAt = $previous->created_at->addSeconds(AuthSettings::otpResendCooldownSeconds());

        if ($cooldownEndsAt->isFuture()) {
            throw DomainException::retryAfter(
                ErrorCode::OtpResendCooldown,
                (int) ceil(now()->diffInSeconds($cooldownEndsAt, absolute: true)),
            );
        }

        if ($previous->resend_count >= AuthSettings::otpMaxResends()) {
            throw DomainException::retryAfter(
                ErrorCode::OtpMaxResends,
                (int) ceil(now()->diffInSeconds($previous->expires_at, absolute: true)),
            );
        }
    }

    /**
     * "Active" means still LIVE, not merely still marked pending: nothing
     * flips a row to `expired` when its clock runs out — only issuing a
     * replacement or attempting a verification does. Without the
     * `expires_at` check, a chain of resends would never reset, and someone
     * who requested a few codes and never typed one would be held at the
     * resend cap permanently, with only the hourly limiter able to forgive
     * them — which it could not, because it is never reached.
     */
    private function activeChallengeFor(PhoneNumber $phone, OtpPurpose $purpose): ?OtpChallenge
    {
        return OtpChallenge::query()
            ->where('phone_e164', $phone->e164)
            ->where('purpose', $purpose->value)
            ->where('status', OtpStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();
    }

    /**
     * Scenario I: never claim a code was sent when it was not. If delivery
     * fails the challenge is withdrawn, so the person is not left staring
     * at an OTP screen waiting for a message that will never arrive.
     */
    private function deliver(PhoneNumber $phone, string $code, OtpPurpose $purpose, OtpChallenge $challenge): void
    {
        try {
            $this->sender->send($phone, $code, $purpose);
        } catch (\Throwable $e) {
            $challenge->forceFill(['status' => OtpStatus::Expired->value])->save();

            throw DomainException::of(ErrorCode::OtpDeliveryFailed, previous: $e);
        }
    }

    /**
     * `random_int` is the CSPRNG; `rand`/`mt_rand` are predictable from a
     * handful of observed codes. Zero-padded so every code has the full
     * length — "0" as a first digit must not shorten it.
     */
    private function generateCode(): string
    {
        $length = AuthSettings::otpLength();

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }
}
