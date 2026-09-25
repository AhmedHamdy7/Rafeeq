<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A driver countering with a place they would rather stop at.
 *
 * ---
 *
 * Maintainer note: the alternative must be a known `place`, not a free coordinate.
 * The passenger has to be able to recognise where they are being sent, and a named
 * place in the shared catalogue is recognisable in a way a pin dropped by somebody
 * else is not. It also means the suggestion survives being read on a screen with no
 * map.
 */
final class SuggestAlternativePickupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // A place from the shared catalogue that you would rather stop at.
            'placeId' => ['required', 'string', 'size:26', 'exists:places,id'],
        ];
    }
}
