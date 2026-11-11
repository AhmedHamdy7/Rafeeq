<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;

final class MoveDepartureRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'departureAt' => ['required_without:minutesFromNow', 'nullable', 'date'],
            'minutesFromNow' => ['required_without:departureAt', 'nullable', 'integer', 'between:-1440,20160'],
        ];
    }
}
