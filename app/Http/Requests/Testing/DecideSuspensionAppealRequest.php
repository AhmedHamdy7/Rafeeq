<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecideSuspensionAppealRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['upheld', 'overturned'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
