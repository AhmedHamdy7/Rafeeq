<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Proposing a different place to be picked up from.
 *
 * ---
 *
 * Maintainer notes, kept out of the rules array because comments there are
 * published as field descriptions to the external mobile team:
 *
 * - 🔴 There is deliberately NO field for the detour. `added_minutes` and
 *   `added_km` are measured by us from the driver's published route, and the
 *   driver's `max_detour_minutes` is checked against that measurement. A requester
 *   who could supply them would be deciding how far out of their way the driver
 *   goes.
 * - There is no `effectiveFrom` field either: `next_trip` is the only value the
 *   schema can record, because the ERD lists `specific_date` with no column to hold
 *   which date. Accepting the keyword and ignoring it would be worse than not
 *   offering it.
 */
final class RequestPickupPointRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Where you would like to be collected from.
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],

            // What to call it, so the driver reads a place rather than a pin —
            // "the south gate", "opposite the pharmacy".
            'label' => ['nullable', 'string', 'max:150'],
        ];
    }
}
