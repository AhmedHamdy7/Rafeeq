<?php

namespace App\Http\Requests\Payment;

use App\Domains\Payment\Enums\DebtSide;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "I paid" — an InstaPay transfer towards one of her debts (PAY-09).
 */
final class SubmitDebtPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Which debt it pays: `driver` (commission) or `passenger` (fees). The two are never mixed.
            'side' => ['required', Rule::enum(DebtSide::class)],

            /*
             * What she sent, in piastres. More than she owes is accepted: the rest is a credit on
             * the same side (PAY-10). Nothing comes off until staff confirm the transfer.
             */
            'amountPiastres' => ['required', 'integer', 'min:100', 'max:10000000'],

            // The reference InstaPay showed her. Staff search their statement for it.
            'instapayReference' => ['required', 'string', 'min:4', 'max:64'],

            /*
             * Optional: a screenshot of the transfer. JPEG, PNG or WebP. Metadata is stripped
             * server-side and the image is never returned.
             */
            'receiptImage' => [
                'nullable', 'file',
                'mimes:jpeg,jpg,png,webp',
                'max:'.config('rafeeq.safety.max_evidence_size_kilobytes'),
            ],
        ];
    }

    public function side(): DebtSide
    {
        return DebtSide::from($this->validated('side'));
    }
}
