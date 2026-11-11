<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;

final class DocumentExpiryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'expiresAt' => ['required', 'date'],
            'vehicleId' => ['nullable', 'string', 'size:26'],
        ];
    }
}
