<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;

final class AgeReliabilityEventRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'hours' => ['required', 'integer', 'between:1,2400'],
        ];
    }
}
