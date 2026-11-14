<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecideReviewReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['removed', 'kept'])],
        ];
    }
}
