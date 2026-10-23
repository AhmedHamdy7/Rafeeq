<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Whether she can ride today, on a waiting-list membership.
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as field descriptions in the OpenAPI
 * document, so they are written for the mobile team — binding standard #38):
 *
 * 🔴 There is no date field, and there will not be one. Feature 03 v5 §8 gives her a toggle —
 * `Available Today` / `Not Available Today` — and the server decides which day that is, in the
 * commute's own timezone. A client-supplied date would let a phone with the wrong clock or the
 * wrong timezone exclude her from a morning she fully intended to ride.
 */
final class SetStandbyAvailabilityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * `true` means you can ride today; `false` means you cannot and should not be offered
             * today's seats. Reversible as often as you like, and it costs nothing either way — no
             * penalty, no record of how often it changed.
             *
             * It only ever affects TODAY. A recurring membership keeps every other day it is
             * waiting for, and tomorrow this is clear again without you sending anything.
             */
            'available' => ['required', 'boolean'],
        ];
    }
}
