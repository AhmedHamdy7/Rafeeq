<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;

final class ExpireWaitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bookingId' => ['required', 'string', 'size:26'],
        ];
    }
}
