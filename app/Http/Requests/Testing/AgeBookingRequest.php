<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;

final class AgeBookingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'between:1,60'],
        ];
    }
}
