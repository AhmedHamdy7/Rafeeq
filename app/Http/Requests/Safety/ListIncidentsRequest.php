<?php

namespace App\Http\Requests\Safety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Which of your reports `GET /incidents` returns (HISTORY-8).
 */
final class ListIncidentsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * Only your reports about this journey — what the past trip's "My reports" shows. Still
             * your own reports only: a booking id that is not yours returns an empty list, not an
             * error, so the filter cannot be used to learn whether somebody else reported a journey.
             */
            'bookingId' => ['nullable', 'string', 'size:26'],
        ];
    }

    public function bookingId(): ?string
    {
        return $this->validated('bookingId');
    }
}
