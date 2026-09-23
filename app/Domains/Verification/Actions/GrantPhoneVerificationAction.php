<?php

namespace App\Domains\Verification\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationMethod;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Support\Facades\DB;

/**
 * Level 1, granted the moment a phone is proven by OTP.
 *
 * It exists as its own Action rather than as a line inside the sign-in flow so
 * that `user_verifications` cannot disagree with `users.phone_verified_at`.
 * Two places recording "this phone is verified" is two places that can drift,
 * and the Verification Centre would then show level 0 for someone who plainly
 * just verified their number.
 */
final readonly class GrantPhoneVerificationAction
{
    public function __construct(private RecomputeTrustLevelAction $recomputeTrustLevel) {}

    public function execute(User $user): UserVerification
    {
        return DB::transaction(function () use ($user): UserVerification {
            $verification = $user->verifications()->firstOrCreate(
                ['type' => VerificationType::Phone->value],
                ['method' => VerificationMethod::Otp->value],
            );

            // Idempotent: every sign-in re-proves the phone, and none of them
            // should look like a new approval in the audit trail.
            if ($verification->status !== VerificationStatus::Approved) {
                $verification->status = VerificationStatus::Approved->value;
                $verification->reviewed_at = now();
                $verification->save();
            }

            $this->recomputeTrustLevel->execute($user);

            return $verification;
        });
    }
}
