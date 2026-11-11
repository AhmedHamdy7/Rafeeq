<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;

final class AgeDebtRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'between:1,120'],
            'side' => ['nullable', 'in:driver,passenger'],
        ];
    }
}
