<?php

namespace App\Domains\Verification\Actions;

use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Enums\VirusScanStatus;
use App\Domains\Verification\Models\UserVerification;
use App\Domains\Verification\Support\VerificationRequirements;
use Illuminate\Support\Facades\DB;

/**
 * Hands a level to the review queue.
 *
 * Submission is a separate step from upload because Chapter 3 §9 says the
 * person reviews everything and may still edit before committing — and
 * because the moment of submission is what freezes the evidence and starts
 * the 24–48 hour clock they were promised.
 *
 * A level is only submittable when every required document is present AND
 * scanned clean. Allowing submission with a pending scan would put an
 * unverified file in front of a reviewer, which is the one place a malicious
 * upload would actually be opened by a human.
 */
final readonly class SubmitVerificationAction
{
    public function execute(User $user, VerificationType $type): UserVerification
    {
        $verification = $user->verifications()->where('type', $type->value)->first();

        if ($verification === null) {
            throw DomainException::of(ErrorCode::VerificationNotSubmittable);
        }

        if ($verification->status === VerificationStatus::Approved) {
            throw DomainException::of(ErrorCode::VerificationAlreadyApproved);
        }

        if ($verification->status === VerificationStatus::Pending) {
            // Idempotent: tapping submit twice on a slow connection must not
            // create a second queue entry or spend another attempt.
            return $verification;
        }

        if ($verification->attempt_count >= (int) config('rafeeq.verification.max_submission_attempts')) {
            throw DomainException::of(ErrorCode::VerificationAttemptsExhausted);
        }

        $this->assertEvidenceComplete($verification, $type);

        return DB::transaction(function () use ($user, $verification): UserVerification {
            $verification->status = VerificationStatus::Pending->value;
            $verification->rejection_reason = null;
            $verification->reviewed_at = null;
            $verification->reviewed_by = null;
            // Counted at submission, not at upload: the limit is on how many
            // times a reviewer's judgement is asked for, not on how many
            // photos someone retakes to get a clear one.
            $verification->attempt_count = $verification->attempt_count + 1;
            $verification->save();

            SecurityLog::record(SecurityEventType::VerificationSubmitted, $user, metadata: [
                'verification_type' => $verification->type->value,
                'attempt' => $verification->attempt_count,
            ]);

            return $verification;
        });
    }

    private function assertEvidenceComplete(UserVerification $verification, VerificationType $type): void
    {
        $required = VerificationRequirements::documentsFor($type);

        if ($required === []) {
            // Phone and organization are proven by a mechanism the server
            // checks itself, so there is nothing for a reviewer to look at.
            throw DomainException::of(ErrorCode::VerificationNotSubmittable);
        }

        $clean = $verification->documents()
            ->where('virus_scan_status', VirusScanStatus::Clean->value)
            ->pluck('kind')
            ->map(fn ($kind) => $kind instanceof \BackedEnum ? $kind->value : $kind)
            ->all();

        foreach ($required as $kind) {
            if (! in_array($kind->value, $clean, true)) {
                throw DomainException::of(ErrorCode::VerificationNotSubmittable);
            }
        }
    }
}
