<?php

namespace App\Domains\Verification\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Verification\Enums\DocumentKind;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Enums\VirusScanStatus;
use App\Domains\Verification\Models\IdentityDocument;
use App\Domains\Verification\Support\DocumentIntake;
use App\Domains\Verification\Support\DocumentStorage;
use App\Domains\Verification\Support\VerificationRequirements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Accepts one document towards one verification level.
 *
 * The order of operations is the security design, not an implementation
 * detail:
 *
 *   1. scan the ORIGINAL bytes — the thing the person actually sent;
 *   2. re-encode to strip metadata and any appended payload;
 *   3. only then write to the private disk.
 *
 * Scanning after re-encoding would scan a file we produced ourselves, which
 * proves nothing about the upload. Writing before scanning would put unscanned
 * bytes in the bucket, and "we deleted it afterwards" is not the same as never
 * having stored it.
 *
 * Nothing here trusts the client's declared MIME type or filename. Both are
 * attacker-controlled, so the decision is made from the bytes.
 */
final readonly class UploadVerificationDocumentAction
{
    public function __construct(
        private DocumentIntake $intake,
        private DocumentStorage $storage,
        private RecomputeTrustLevelAction $recomputeTrustLevel,
    ) {}

    public function execute(
        User $user,
        VerificationType $type,
        DocumentKind $kind,
        UploadedFile $file,
    ): IdentityDocument {
        if (! VerificationRequirements::accepts($type, $kind)) {
            throw DomainException::of(ErrorCode::DocumentKindNotAccepted);
        }

        $verification = $this->openVerification($user, $type);

        // Scan, strip and store — in that order, for the reasons documented on
        // DocumentIntake. Nothing reaches the disk unless it passed.
        $stored = $this->intake->accept($user, $file, auditContext: [
            'verification_type' => $type->value,
            'kind' => $kind->value,
        ]);

        return DB::transaction(function () use ($user, $verification, $kind, $stored): IdentityDocument {
            // One document per kind per level: re-uploading a blurry ID front
            // replaces it rather than leaving a reviewer to guess which of two
            // is current.
            $this->replaceExisting($verification->id, $kind);

            $document = new IdentityDocument;

            $document->fill([
                'user_verification_id' => $verification->id,
                'kind' => $kind->value,
                'file_path' => $stored['path'],
                'file_hash' => $stored['hash'],
                'mime_type' => $stored['mime'],
                'size_bytes' => $stored['size'],
                // §18 retention: documents are not kept forever. The date is
                // set at upload so a purge job never has to infer it.
                'purge_after' => now()->addDays(90)->toDateString(),
            ]);

            // Not fillable on purpose — a scan result must never be something a
            // request can assert about its own upload.
            $document->virus_scan_status = VirusScanStatus::Clean->value;

            $document->save();

            // Uploading again after a rejection puts the level back to work,
            // so the Verification Centre stops showing a stale "needs action".
            if ($verification->status === VerificationStatus::ActionNeeded
                || $verification->status === VerificationStatus::Rejected) {
                $verification->status = VerificationStatus::NotStarted->value;
                $verification->rejection_reason = null;
                $verification->save();

                $this->recomputeTrustLevel->execute($user);
            }

            return $document;
        });
    }

    /**
     * The row for this level, created on first use. An already-approved level
     * is refused rather than quietly reopened: overwriting a reviewer's
     * decision by uploading a new photo would make approval meaningless.
     */
    private function openVerification(User $user, VerificationType $type)
    {
        $verification = $user->verifications()->firstOrCreate(
            ['type' => $type->value],
            ['method' => VerificationRequirements::methodFor($type)->value],
        );

        if ($verification->status === VerificationStatus::Approved) {
            throw DomainException::of(ErrorCode::VerificationAlreadyApproved);
        }

        if ($verification->attempt_count >= (int) config('rafeeq.verification.max_submission_attempts')) {
            throw DomainException::of(ErrorCode::VerificationAttemptsExhausted);
        }

        return $verification;
    }

    private function replaceExisting(string $verificationId, DocumentKind $kind): void
    {
        IdentityDocument::query()
            ->where('user_verification_id', $verificationId)
            ->where('kind', $kind->value)
            ->get()
            ->each(function (IdentityDocument $stale): void {
                // Delete the bytes, then the row. The other order can leave a
                // file with no owner, which no purge job will ever find.
                $this->storage->delete($stale);
                $stale->delete();
            });
    }
}
