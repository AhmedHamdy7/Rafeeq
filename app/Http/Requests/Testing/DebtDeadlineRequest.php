<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;

final class DebtDeadlineRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'side' => ['nullable', 'in:driver,passenger'],
        ];
    }
}
