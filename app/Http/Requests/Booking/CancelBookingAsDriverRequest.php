<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The driver removing one passenger (C-5).
 */
final class CancelBookingAsDriverRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Why, for support and for his appeal. Kept on the booking; never pushed to her.
            'reason' => ['required', 'string', 'min:3', 'max:255'],

            // `day` (default) removes her from this booking's day only; `all` from every upcoming
            // day of her seat on this commute, ending her recurring seat.
            'scope' => ['nullable', 'string', 'in:day,all'],
        ];
    }
}
