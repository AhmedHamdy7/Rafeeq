<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "I've arrived — Sara isn't here yet": the driver's GPS-checked arrival at one passenger's
 * pickup, which starts her 5-minute grace.
 *
 * ---
 *
 * Maintainer notes:
 *
 * - There is no `graceSeconds` field. How long the platform asks a driver to wait is a policy
 *   number the dashboard owns (`trip.wait_grace_seconds`), and letting the client send it would
 *   mean the promise made to a passenger was whatever the app happened to put in the request.
 *   Nor can the wait be extended any more (TRIP-11, B7): every passenger gets the same grace.
 * - `lat`/`lng` are required because the grace starts here and the fees start from the grace
 *   (TRIP-10, decision C14). See ArriveAtPickupAction.
 */
final class StartWaitTimerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Who is being waited for.
            'bookingId' => ['required', 'string', 'size:26'],

            // Where the car is now. Refused with 409 NOT_AT_PICKUP (with `distanceMeters`) unless
            // it is within 150 m of this passenger's pickup point.
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
