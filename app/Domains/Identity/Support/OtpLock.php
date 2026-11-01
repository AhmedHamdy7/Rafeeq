<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Enums\OtpStatus;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Carbon\CarbonInterface;

/**
 * The timed lock after too many wrong codes (AUTH-08, follow-up F1-5).
 *
 * Before this, the attempt cap only killed one challenge: the app asked for a new code and
 * the guessing started over, so "three tries" was really "three tries per resend". The lock
 * belongs to the NUMBER and the purpose, so a fresh challenge inherits it — and both
 * endpoints answer `AUTH_OTP_LOCKED` with `fields.lockedUntil` and `Retry-After`, so the app
 * can show one countdown instead of guessing.
 *
 * 🔒 It says nothing about accounts. A lock exists only because someone typed wrong codes
 * at this number, which an attacker can do whether or not an account is behind it — so the
 * answer cannot tell anyone which numbers are registered (scenario D stays true).
 */
final class OtpLock
{
    /**
     * Burns the challenge and locks the number. Called the moment the last attempt is spent.
     */
    public static function engage(OtpChallenge $challenge): never
    {
        $until = now()->addSeconds(AuthSettings::otpLockSeconds());

        $challenge->forceFill([
            'status' => OtpStatus::Blocked->value,
            'locked_until' => $until,
        ])->save();

        SecurityLog::record(SecurityEventType::OtpLocked, metadata: [
            'purpose' => $challenge->purpose->value,
            'phone' => SecurityLog::maskPhone($challenge->phone_e164),
            'locked_until' => $until->toIso8601ZuluString(),
        ]);

        self::refuse($until);
    }

    /**
     * The guard on `POST /auth/otp/request`: no new code while the number is locked.
     */
    public static function assertNotLocked(PhoneNumber $phone, OtpPurpose $purpose): void
    {
        $locked = OtpChallenge::query()
            ->where('phone_e164', $phone->e164)
            ->where('purpose', $purpose->value)
            ->where('locked_until', '>', now())
            ->orderByDesc('locked_until')
            ->first();

        if ($locked !== null) {
            self::refuse($locked->locked_until);
        }
    }

    public static function refuse(CarbonInterface $until): never
    {
        throw DomainException::of(
            ErrorCode::OtpLocked,
            fields: ['lockedUntil' => [$until->copy()->utc()->toIso8601ZuluString()]],
            headers: ['Retry-After' => (string) max((int) ceil(now()->diffInSeconds($until, absolute: true)), 1)],
        );
    }
}
