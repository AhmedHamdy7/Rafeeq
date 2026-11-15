<?php

namespace App\Http\Requests\Testing;

use App\Domains\Trip\Enums\DisputeResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecideAttendanceDisputeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', Rule::enum(DisputeResolution::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
