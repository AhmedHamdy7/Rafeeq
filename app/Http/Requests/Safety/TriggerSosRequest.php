<?php

namespace App\Http\Requests\Safety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pressing the SOS button.
 *
 * ---
 *
 * Maintainer notes:
 *
 * - **Everything here is optional**, deliberately. This endpoint must succeed for somebody holding
 *   a phone in a bad situation, and every required field is another way for it to return 422
 *   instead of recording an emergency. An empty body is a valid SOS.
 * - `tripSessionId` is optional because the server can work it out. Somebody pressing this has
 *   better things to do than tell us which journey they are on.
 * - There is no `countdownSeconds`: how long somebody has to take it back is platform policy, not
 *   something the app announces.
 */
final class TriggerSosRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * 🔒 A SILENT alert — no sound, no vibration. For the situation where being seen to ask
             * for help is itself the danger.
             */
            'isDiscreet' => ['sometimes', 'boolean'],

            // Where they are, if the phone knows. Both or neither.
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],

            // The run they are on, if the client happens to know it. Looked up otherwise.
            'tripSessionId' => ['nullable', 'string', 'size:26'],
        ];
    }
}
