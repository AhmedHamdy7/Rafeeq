<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;

final class CancelTripDayRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * Optional, and kept on the day for the driver's own record and for staff. It is NOT
             * sent to the passengers: a reason can be personal, and the notice shows on a lock
             * screen.
             */
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
