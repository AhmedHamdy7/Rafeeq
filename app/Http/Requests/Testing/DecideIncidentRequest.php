<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecideIncidentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['UNDER_REVIEW', 'RESOLVED', 'CLOSED'])],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
