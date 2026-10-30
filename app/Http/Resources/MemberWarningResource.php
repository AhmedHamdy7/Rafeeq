<?php

namespace App\Http\Resources;

use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One warning, as the person it is about reads it (Feature 03 v5 §239).
 *
 * > *"Provide clear explanation when warnings displayed, with dispute link."*
 *
 * 🔴 So this payload carries the explanation rather than leaving the client to compose one from a
 * count: which warning it was, how many come before a fee becomes possible, whether this one
 * crossed that line, and whether it still stands. A bare `ordinal` would let two clients word the
 * same warning differently, and the wording is the part that decides whether somebody understands
 * what has been recorded about her.
 *
 * 🔒 Reachable only from her own list. There is no endpoint at any access level that returns
 * anybody else's — a warning is an accusation, and one person's is nobody else's business.
 *
 * @mixin MemberWarning
 */
final class MemberWarningResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => strtoupper($this->kind->value),
            'bookingId' => $this->booking_id,

            // "1st", "2nd" — what she was told at the time, and never recomputed afterwards.
            'ordinal' => (int) $this->ordinal,

            /*
             * How many warnings of this kind come before a fee becomes possible — two for a
             * lateness, one for a missed commute. Sent rather than hard-coded in the app so the
             * copy stays true if the policy ever changes.
             */
            'warningsBeforeFee' => $this->kind->warningsBeforeFee(),

            /*
             * ⚠️ Whether a fee has become POSSIBLE, never an amount. v5: "amount not shown until
             * Payments rules", and the cancellation policy is still deferred — so there is no
             * number to show and a client must not imply one.
             */
            'feeEligible' => (bool) $this->fee_eligible,

            'occurredOn' => $this->occurred_on->toDateString(),
            'deliveredAt' => $this->delivered_at?->toIso8601String(),

            /*
             * 🔒 Whether it still counts against her. A warning overturned on dispute stays
             * readable — like a reversed reliability event — because deleting it would leave her
             * unable to show that she had been cleared.
             */
            'stillStands' => $this->dispute_resolution === null
                || $this->dispute_resolution->value !== 'overturned',

            'disputedAt' => $this->disputed_at?->toIso8601String(),
            'disputeReason' => $this->dispute_reason,
            'disputeResolution' => $this->dispute_resolution?->value,
            'disputeResolvedAt' => $this->dispute_resolved_at?->toIso8601String(),

            /*
             * Whether she can still answer it. One dispute per warning, which is what v5 means by
             * "appeal once" — a second attempt at the same decision is a conversation with support,
             * not another form.
             */
            'canDispute' => $this->disputed_at === null,
        ];
    }
}
