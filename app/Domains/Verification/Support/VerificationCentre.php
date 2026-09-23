<?php

namespace App\Domains\Verification\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;

/**
 * The Verification Centre read model (MASTER_PLAN §247): a progress figure and
 * one row per level, each in one of four states — verified, required, under
 * review, or needs action with a reason.
 *
 * Built server-side rather than assembled by the client because the same
 * answer has to drive two different things: what the Centre screen shows, and
 * what the gate refuses. A client that computed its own view of "what is
 * missing" could offer an action the server then refuses, or hide one it would
 * have allowed.
 *
 * The screen's closing line is the point of all of it: *"Badges show what was
 * checked. They never guarantee a person's behaviour."* Nothing here claims
 * more than which evidence was seen.
 */
final class VerificationCentre
{
    /**
     * @return array{level: int, of: int, percentage: int, rows: array<int, array<string, mixed>>}
     */
    public static function for(User $user): array
    {
        $verifications = $user->verifications()
            ->with('documents')
            ->get()
            ->keyBy(fn (UserVerification $verification) => $verification->type->value);

        $rows = [];
        $approved = 0;

        foreach (VerificationRequirements::levels() as $type) {
            $verification = $verifications->get($type->value);
            $status = self::effectiveStatus($verification);

            if ($status === VerificationStatus::Approved) {
                $approved++;
            }

            $rows[] = self::row($type, $verification, $status);
        }

        $of = VerificationRequirements::levelCount();

        return [
            'level' => $approved,
            'of' => $of,
            'percentage' => (int) round($approved / $of * 100),
            'rows' => $rows,
        ];
    }

    /**
     * Levels that are still missing, for the gate to name in its refusal.
     *
     * @param  array<int, VerificationType>  $required
     * @return array<int, string>
     */
    public static function missingFrom(User $user, array $required): array
    {
        $approved = $user->verifications()
            ->where('status', VerificationStatus::Approved->value)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('type')
            ->map(fn ($type) => $type instanceof VerificationType ? $type->value : $type)
            ->all();

        return array_values(array_diff(
            array_map(fn (VerificationType $type) => $type->value, $required),
            $approved,
        ));
    }

    /**
     * An approval with a past `expires_at` is no longer an approval. Reading it
     * as one would keep a badge on screen for a lapsed document, which is the
     * kind of stale trust signal the whole feature exists to avoid.
     */
    private static function effectiveStatus(?UserVerification $verification): VerificationStatus
    {
        if ($verification === null) {
            return VerificationStatus::NotStarted;
        }

        if ($verification->status === VerificationStatus::Approved
            && $verification->expires_at !== null
            && $verification->expires_at->isPast()) {
            return VerificationStatus::Expired;
        }

        return $verification->status;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(VerificationType $type, ?UserVerification $verification, VerificationStatus $status): array
    {
        /*
         * These three lists are built with foreach and an accumulator rather
         * than `array_map`/`array_diff`, which read better but cost the
         * published API contract its element types: the OpenAPI generator
         * infers `array<string>` from this shape and only `array<unknown>`
         * from those. `OpenApiDocumentTest` fails if that regresses — it is
         * what caught this in the first place.
         */
        $requiredDocuments = [];

        foreach (VerificationRequirements::documentsFor($type) as $kind) {
            $requiredDocuments[] = $kind->value;
        }

        $uploaded = [];

        if ($verification !== null) {
            foreach ($verification->documents as $document) {
                $uploaded[] = $document->kind->value;
            }
        }

        $missing = [];

        foreach ($requiredDocuments as $kind) {
            if (! in_array($kind, $uploaded, true)) {
                $missing[] = $kind;
            }
        }

        return [
            'type' => $type->value,
            'status' => strtoupper($status->value),
            // What actually happened, falling back to the default only before
            // anything has. A level proven by email domain must not be
            // reported as a badge review just because that is the usual route
            // — the Centre describes this account, not the general case.
            'method' => ($verification?->method ?? VerificationRequirements::methodFor($type))->value,
            'needsReview' => VerificationRequirements::needsReview($type),
            'requiredDocuments' => $requiredDocuments,
            'uploadedDocuments' => $uploaded,
            // What is still needed before this level can be submitted — the
            // Centre turns this straight into the row's call to action.
            'missingDocuments' => $missing,
            // Written for the person by the reviewer, so it is shown verbatim.
            'actionNeededReason' => $status === VerificationStatus::ActionNeeded
                ? $verification?->rejection_reason
                : null,
            'submittedAt' => $status === VerificationStatus::Pending
                ? $verification?->updated_at?->toIso8601String()
                : null,
            'verifiedAt' => $status === VerificationStatus::Approved
                ? $verification?->reviewed_at?->toIso8601String()
                : null,
            'expiresAt' => $verification?->expires_at?->toIso8601String(),
            // Cast on the outside as well: `max()` returns the wider of its
            // arguments' types, which the contract would describe as untyped.
            'attemptsRemaining' => (int) max(0, (int) config('rafeeq.verification.max_submission_attempts')
                - (int) ($verification?->attempt_count ?? 0)),
        ];
    }
}
