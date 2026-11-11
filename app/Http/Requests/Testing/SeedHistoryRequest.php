<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;

final class SeedHistoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:no_show,late_boarding,late_cancel'],
            'count' => ['required', 'integer', 'between:1,10'],
        ];
    }
}
