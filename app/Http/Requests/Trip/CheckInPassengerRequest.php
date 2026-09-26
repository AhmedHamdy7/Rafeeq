<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Sara arrived" — the driver confirming somebody is in the car.
 *
 * ---
 *
 * Maintainer notes:
 *
 * - There is no `status` field. The driver taps one button; whether that reads as present
 *   or late is decided from whether a wait timer was running (see
 *   `ConfirmAttendanceAction::arrivalStatus`). Letting the client send it would make the
 *   passenger's punctuality something the app chose.
 * - `lat`/`lng` are OPTIONAL and are corroboration only. A confirmation that required a
 *   good fix would fail in a basement car park, and the driver would learn to check people
 *   in from the street to make it work.
 */
final class CheckInPassengerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Whose seat is being confirmed.
            'bookingId' => ['required', 'string', 'size:26'],

            // Where the driver's phone says they are, if it offered a position. Recorded
            // beside the confirmation as supporting evidence for any later dispute.
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ];
    }
}
