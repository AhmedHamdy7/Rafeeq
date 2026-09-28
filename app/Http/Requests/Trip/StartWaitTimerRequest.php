<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Sara isn't at Point 90 yet — start the 5-minute wait timer".
 *
 * ---
 *
 * Maintainer note: there is no `graceSeconds` field. How long the platform asks a driver
 * to wait is a policy number the dashboard owns (`trip.wait_grace_seconds`), and letting
 * the client send it would mean the promise made to a passenger was whatever the app
 * happened to put in the request. The driver can add time with the extend endpoint, which
 * is a different thing: it is recorded separately, as generosity rather than as the rule.
 */
final class StartWaitTimerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Who is being waited for.
            'bookingId' => ['required', 'string', 'size:26'],
        ];
    }
}
