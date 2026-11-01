<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A driver appealing one of his fees (SAFE-11).
 */
final class AppealFeeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * What happened, and the evidence he has (a report with photos, a garage receipt). Staff
             * read it; an emergency is not a free pass on its own (PAY-24).
             */
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
