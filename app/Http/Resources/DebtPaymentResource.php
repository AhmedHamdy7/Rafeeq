<?php

namespace App\Http\Resources;

use App\Domains\Payment\Models\DebtPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An InstaPay payment she says she made (PAY-09). Nothing comes off her debt until `status` is
 * `confirmed`. 🔒 The receipt is never returned.
 *
 * @mixin DebtPayment
 */
final class DebtPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // `driver` (commission) or `passenger` (fees).
            'side' => $this->side->value,
            'amountPiastres' => $this->amount_piastres,
            'instapayReference' => $this->instapay_reference,
            // `pending_confirmation`, `confirmed` or `rejected`.
            'status' => $this->status->value,
            'hasReceipt' => $this->receipt_path !== null,
            // Why staff refused it, to show her ("no transfer with this reference").
            'decisionNote' => $this->decision_note,
            'createdAt' => $this->created_at->toIso8601String(),
            'decidedAt' => $this->decided_at?->toIso8601String(),
        ];
    }
}
