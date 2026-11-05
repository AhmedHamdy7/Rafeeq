<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

final class OfferReplacementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'seatRequestId' => ['required', 'string', 'size:26'],
        ];
    }
}
