<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `Receive Requests During Ride`, for one morning.
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as field descriptions in the OpenAPI
 * document, so they are written for the mobile team — binding standard #38):
 *
 * ⚠️ **Nothing acts on this yet.** The mid-journey seat request it governs is not built, so turning
 * it off changes nothing you can observe today. It is stored now so that a driver who has already
 * said no is not asked the first time the feature ships.
 */
final class SetReceiveRequestsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * `true` means you are willing to be asked for a seat while this journey is under way;
             * `false` means you are not. Defaults to `true` on every day, and applies to **this day
             * only** — tomorrow is a separate answer, because whether you can take another
             * passenger depends on who is already aboard and how the morning is going.
             *
             * Reversible as often as you like, including mid-journey.
             */
            'receiveRequests' => ['required', 'boolean'],
        ];
    }
}
