<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

final class ListDriverWaitlistRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tripId' => ['nullable', 'string', 'max:26'],
        ];
    }
}
