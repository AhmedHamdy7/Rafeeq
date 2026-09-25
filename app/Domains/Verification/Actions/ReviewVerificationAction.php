<?php

namespace App\Domains\Verification\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Support\Facades\DB;

/**
 * A reviewer's decision on one level.
 *
 * The HTTP/Livewire surface for this belongs to the admin dashboard (Phase
 * 13); the rule about what a decision MEANS belongs here, in Phase 3, because
 * three of the four Verification Centre states — under review, verified, needs
 * action with a reason — only exist once something can move between them.
 *
 * Chapter 3 §10: a rejection reason is mandatory, and it is written for the
 * person, not for the reviewer's own notes. "Rejected" with nothing actionable
 * leaves someone stuck with no idea what to change, which is how a support
 * queue fills up.
 */
final readonly class ReviewVerificationAction
{
    public function __construct(private RecomputeTrustLevelAction $recomputeTrustLevel) {}

    public function approve(UserVerification $verification, AdminUser $reviewer, ?\DateTimeInterface $expiresAt = null): UserVerification
    {
        $this->assertUnderReview($verification);

        return DB::transaction(function () use ($verification, $reviewer, $expiresAt): UserVerification {
            $verification->status = VerificationStatus::Approved->value;
            $verification->reviewed_by = $reviewer->id;
            $verification->reviewed_at = now();
            $verification->rejection_reason = null;

            if ($expiresAt !== null) {
                // A document with an expiry (an ID, a licence) grants the level
                // only until then — `RecomputeTrustLevelAction` stops counting
                // it after that without anyone having to run a sweep.
                $verification->expires_at = $expiresAt;
            }

            $verification->save();

            $this->recomputeTrustLevel->execute($verification->user);

            SecurityLog::record(SecurityEventType::VerificationApproved, $verification->user, metadata: [
                'verification_type' => $verification->type->value,
                'reviewer_id' => $reviewer->id,
            ]);

            return $verification;
        });
    }

    /**
     * "Ask info" — the dashboard queue's second button, and the only other verdict.
     *
     * Named for what it does. It was called `reject()`, which is what a caller would
     * reasonably expect to set `Rejected` — it does not, and must not: the level goes
     * to `action_needed` with something the person can fix.
     *
     * 🔴 There is deliberately NO hard reject. The terminal state is reached by
     * attempt exhaustion (`max_submission_attempts` → `VERIFICATION_ATTEMPTS_EXHAUSTED`),
     * which is a rule the person can see coming, rather than by a reviewer closing a
     * door in one click. Obvious fraud is handled by suspending the ACCOUNT — a
     * different and more honest lever, and the one Chapter 12's User Management
     * actually lists. `VerificationStatus::Rejected` therefore stays unset.
     *
     * @param  string  $reason  shown to the person verbatim, so it has to tell
     *                          them what to do differently
     */
    public function requestMoreInfo(UserVerification $verification, AdminUser $reviewer, string $reason): UserVerification
    {
        $this->assertUnderReview($verification);

        if (trim($reason) === '') {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'reason' => [__('validation.required', ['attribute' => 'reason'])],
            ]);
        }

        return DB::transaction(function () use ($verification, $reviewer, $reason): UserVerification {
            // `action_needed`, not `rejected`: the person can fix this and try
            // again, and the Verification Centre shows it as a row with
            // something to do rather than a dead end.
            $verification->status = VerificationStatus::ActionNeeded->value;
            $verification->reviewed_by = $reviewer->id;
            $verification->reviewed_at = now();
            $verification->rejection_reason = $reason;
            $verification->save();

            $this->recomputeTrustLevel->execute($verification->user);

            SecurityLog::record(SecurityEventType::VerificationRejected, $verification->user, metadata: [
                'verification_type' => $verification->type->value,
                'reviewer_id' => $reviewer->id,
            ]);

            return $verification;
        });
    }

    private function assertUnderReview(UserVerification $verification): void
    {
        if ($verification->status === VerificationStatus::Approved) {
            throw DomainException::of(ErrorCode::VerificationAlreadyApproved);
        }

        // Only a submitted level may be decided. Deciding one that was never
        // submitted would approve evidence nobody claimed was ready.
        if ($verification->status !== VerificationStatus::Pending) {
            throw DomainException::of(ErrorCode::VerificationNotSubmittable);
        }
    }
}
