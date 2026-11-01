<?php

namespace App\Http\Resources;

use App\Domains\Identity\Models\SuspensionAppeal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member's appeal against her hold (AUTH-19, follow-up F1-3).
 *
 * 🔒 Not the staff note and not who decided it — the outcome and when, as with the fee appeal.
 *
 * @mixin SuspensionAppeal
 */
final class SuspensionAppealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // 1, or 2 for the second appeal that came with new evidence.
            'attempt' => $this->attempt,
            // `pending` until staff decide; `upheld` (the hold stands) or `overturned` (lifted).
            'status' => $this->resolution?->value ?? 'pending',
            'createdAt' => $this->created_at->toIso8601String(),
            'resolvedAt' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
