<?php

namespace App\Http\Resources;

use App\Domains\Identity\Actions\AppealSuspensionAction;
use App\Domains\Identity\Models\SuspensionAppeal;
use App\Domains\Identity\Models\User;

/**
 * What a suspended member is told about their own hold (screen 35), in one shape for both
 * places that say it: `GET /v1/auth/me` and the `ACCOUNT_SUSPENDED` refusal.
 *
 * 🔒 Not the staff note, not who suspended them, not the report it came from. The member
 * gets a reference to quote and a time; the reasons stay with the case.
 */
final class SuspensionSummary
{
    /**
     * @return array{caseNumber: string|null, reasonCode: string|null, suspendedAt: string|null, reviewDueAt: string|null}
     */
    public static function for(User $user): array
    {
        $suspension = $user->activeSuspension;

        return [
            // `null` only for an account suspended before suspensions were recorded.
            'caseNumber' => $suspension?->case_number,
            'reasonCode' => $suspension?->reason_code->value ?? $user->suspension_reason,
            'suspendedAt' => $suspension?->suspended_at->toIso8601String(),
            'reviewDueAt' => $suspension?->review_due_at->toIso8601String(),
        ];
    }

    /**
     * The banner's shape on `user.suspension` (AUTH-19, follow-up F1-3): the summary above plus
     * whether this is a permanent ban, and where her appeal stands.
     *
     * 🔴 `permanent: false` is the common case and means "stay in the app": `nextStep` is `HOME`,
     * show the banner with Appeal. `permanent: true` is the only case behind
     * `RESTRICTED_ACCOUNT`.
     *
     * @return array{caseNumber: string|null, reasonCode: string|null, suspendedAt: string|null, reviewDueAt: string|null, permanent: bool, appeal: array{canAppeal: bool, appealBy: string|null, needsNewEvidence: bool, appeals: list<array{id: string, attempt: int, status: string, createdAt: string, resolvedAt: string|null}>}}
     */
    public static function forMember(User $user): array
    {
        $suspension = $user->activeSuspension;
        $appeals = $suspension?->appeals ?? collect();
        $closesAt = $suspension === null ? null : AppealSuspensionAction::nextWindowClosesAt($suspension);

        return [
            ...self::for($user),
            'permanent' => $suspension?->is_permanent === true,
            'appeal' => [
                // Whether `POST /account/suspension/appeal` would accept one right now.
                'canAppeal' => $closesAt !== null,
                'appealBy' => $closesAt?->toIso8601String(),
                // A second appeal needs `evidence` the first did not have.
                'needsNewEvidence' => $appeals->isNotEmpty(),
                'appeals' => self::appealsOf($appeals->all()),
            ],
        ];
    }

    /**
     * Its own method with a declared shape so the OpenAPI document types each item rather than
     * publishing `mixed`.
     *
     * @param  array<int, SuspensionAppeal>  $appeals
     * @return array<int, array{id: string, attempt: int, status: string, createdAt: string, resolvedAt: string|null}>
     */
    private static function appealsOf(array $appeals): array
    {
        $items = [];

        foreach ($appeals as $appeal) {
            $items[] = [
                'id' => (string) $appeal->id,
                'attempt' => (int) $appeal->attempt,
                'status' => (string) ($appeal->resolution?->value ?? 'pending'),
                'createdAt' => (string) $appeal->created_at->toIso8601String(),
                'resolvedAt' => $appeal->resolved_at?->toIso8601String(),
            ];
        }

        return $items;
    }
}
