<?php

namespace App\Http\Requests\Payment;

use App\Domains\Payment\Enums\DebtSide;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One week of one of her debts (PAY-35).
 */
final class DebtStatementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // `driver` or `passenger`. Default `driver`.
            'side' => ['sometimes', Rule::enum(DebtSide::class)],

            /*
             * Any date inside the week wanted (`Y-m-d`). The week runs Saturday to Friday, Cairo
             * time (PAY-11). Default: this week.
             */
            'weekOf' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    public function side(): DebtSide
    {
        return DebtSide::from($this->validated('side', DebtSide::Driver->value));
    }
}
