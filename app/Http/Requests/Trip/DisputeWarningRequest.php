<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Her answer to a warning recorded about her.
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as field descriptions in the OpenAPI
 * document, so they are written for the mobile team — binding standard #38):
 *
 * One dispute per warning. Raising it does **not** pause the escalation — if it did, every warning
 * would be disputed as a matter of course — and a reviewer decides. An overturned warning stops
 * counting and stays readable, so she can show she was cleared.
 */
final class DisputeWarningRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * What happened, in her words. **Required**, and this is the one dispute form in the
             * system where that is right: there is no evidence attached to a warning for a reviewer
             * to weigh, so her account of the morning is the entire case.
             */
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
