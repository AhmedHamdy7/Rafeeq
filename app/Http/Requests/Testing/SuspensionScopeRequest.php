<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;

final class SuspensionScopeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'scope' => ['required', 'in:account,vehicle'],
            'reason' => ['nullable', 'string', 'max:255'],
            'vehicleId' => ['nullable', 'string', 'size:26'],
        ];
    }
}
