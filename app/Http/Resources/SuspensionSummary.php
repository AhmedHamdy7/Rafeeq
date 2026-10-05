<?php

namespace App\Http\Resources;

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
}
