<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Enums\OtpStatus;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Support\AuthSettings;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Checks a submitted code against a challenge and, on success, burns it.
 *
 * Deliberately scoped to the code itself: it returns the verified phone
 * number and nothing about accounts. Whoever called it decides what the
 * proof is worth — signing in, resetting a PIN, authorising a phone change.
 * That separation is what keeps pitfall #31 (a code proving one thing being
 * accepted as proof of another) structurally impossible rather than a rule
 * someone has to remember.
 */
final readonly class VerifyOtpAction
{
    /**
     * @param  array<int, OtpPurpose>  $allowedPurposes  what the CALLING endpoint is
     *                                                   willing to accept. A challenge issued for anything else is
     *                                                   refused as if it did not exist.
     */
    public function execute(string $challengeId, string $code, array $allowedPurposes): OtpChallenge
    {
        $this->assertWithinAttemptRateLimit($challengeId);

        $challenge = OtpChallenge::find($challengeId);

        // Same answer for "no such challenge" and "a challenge for a purpose
        // you are not allowed to spend here" — the caller learns nothing
        // about challenges that are not theirs to use.
        if ($challenge === null || ! in_array($challenge->purpose, $allowedPurposes, true)) {
            throw DomainException::of(ErrorCode::OtpChallengeNotFound);
        }

        $this->assertUsable($challenge);
        $this->assertCodeMatches($challenge, $code);

        // Single use (Chapter 2 §12): the row moves out of `pending` in the
        // same statement that checks it is still pending, so two requests
        // racing with the same correct code cannot both win.
        $burned = OtpChallenge::query()
            ->whereKey($challenge->id)
            ->where('status', OtpStatus::Pending->value)
            ->update([
                'status' => OtpStatus::Verified->value,
                'verified_at' => now(),
            ]);

        if ($burned === 0) {
            throw DomainException::of(ErrorCode::OtpInvalid);
        }

        SecurityLog::record(SecurityEventType::OtpVerified, metadata: [
            'purpose' => $challenge->purpose->value,
            'phone' => SecurityLog::maskPhone($challenge->phone_e164),
        ]);

        return $challenge->refresh();
    }

    public static function phoneOf(OtpChallenge $challenge): PhoneNumber
    {
        return PhoneNumber::fromRaw($challenge->phone_e164);
    }

    /**
     * A six-digit code is only 10^6 wide, so the per-challenge attempt
     * counter alone is not enough if an attacker can retry faster than the
     * row can be updated. This caps the request rate as well.
     */
    private function assertWithinAttemptRateLimit(string $challengeId): void
    {
        $key = 'otp:verify:'.$challengeId;

        if (RateLimiter::tooManyAttempts($key, AuthSettings::otpVerificationsPerChallengePerMinute())) {
            throw DomainException::retryAfter(ErrorCode::OtpMaxAttempts, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);
    }

    private function assertUsable(OtpChallenge $challenge): void
    {
        if ($challenge->status !== OtpStatus::Pending) {
            // Each dead state gets the answer that tells the app what to do
            // about it: offer a resend for an expired or burnt-out challenge,
            // and treat an already-spent one as simply wrong (scenario E —
            // "only the newest code works").
            throw DomainException::of(match ($challenge->status) {
                OtpStatus::Expired => ErrorCode::OtpExpired,
                OtpStatus::Blocked => ErrorCode::OtpMaxAttempts,
                default => ErrorCode::OtpInvalid,
            });
        }

        if ($challenge->isExpired()) {
            $challenge->forceFill(['status' => OtpStatus::Expired->value])->save();

            throw DomainException::of(ErrorCode::OtpExpired);
        }

        if (! $challenge->hasAttemptsRemaining()) {
            $challenge->forceFill(['status' => OtpStatus::Blocked->value])->save();

            throw DomainException::of(ErrorCode::OtpMaxAttempts);
        }
    }

    private function assertCodeMatches(OtpChallenge $challenge, string $code): void
    {
        if (Hash::check($code, $challenge->code_hash)) {
            return;
        }

        // Counted atomically: a burst of parallel guesses must each consume
        // an attempt, which a read-modify-write would let them share.
        DB::table('otp_challenges')->where('id', $challenge->id)->increment('attempt_count');

        $challenge->refresh();

        SecurityLog::record(SecurityEventType::OtpFailed, metadata: [
            'purpose' => $challenge->purpose->value,
            'phone' => SecurityLog::maskPhone($challenge->phone_e164),
            'attempt_count' => $challenge->attempt_count,
        ]);

        if (! $challenge->hasAttemptsRemaining()) {
            $challenge->forceFill(['status' => OtpStatus::Blocked->value])->save();

            throw DomainException::of(ErrorCode::OtpMaxAttempts);
        }

        throw DomainException::of(ErrorCode::OtpInvalid);
    }
}
