<?php

namespace App\Http\Resources;

use App\Domains\Payment\Models\FeeAppeal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A driver's appeal against one of his fees (SAFE-11).
 *
 * @mixin FeeAppeal
 */
final class FeeAppealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The fee line appealed (a `lines[].id` of the statement).
            'debtEntryId' => $this->debt_entry_id,
            // `pending` until staff decide; `upheld` (the fee stands) or `overturned` (credited back).
            'status' => $this->resolution?->value ?? 'pending',
            'createdAt' => $this->created_at->toIso8601String(),
            'resolvedAt' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
