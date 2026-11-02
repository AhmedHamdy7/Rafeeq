<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Her note to her driver on one booking (TRIPS-17).
 */
final class UpdatePickupNoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Up to 200 characters. `null` or an empty string clears it. A phone number, an email or
            // a social handle is refused, exactly as in the trip chat (`CHAT_CONTACT_INFO_NOT_ALLOWED`).
            'note' => ['present', 'nullable', 'string', 'max:200'],
        ];
    }
}
