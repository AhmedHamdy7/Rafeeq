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
        ];
    }
}
