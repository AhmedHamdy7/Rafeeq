<?php

namespace App\Http\Requests\Trip;

use App\Domains\Trip\Enums\ActiveTripCancelReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cancel Active Trip: stopping a run that has already set off (TRIP-22).
 *
 * ---
 *
 * Maintainer notes:
 *
 * - The reason is REQUIRED here, unlike "cancel today" where it is optional. This is the heaviest
 *   of the driver's mid-journey endings: it stops a car with people in it, it goes on her
 *   reliability record at the heaviest weight, and she may appeal it.
 * - It is a CATEGORY since F-13 (2026-10-31), not free text: PAY-24 (breakdown, accident,
 *   emergency) and PAY-25 (another reason) price it differently, and a sentence cannot be priced.
 *   Her own words moved to `note`. Neither is sent to the passengers — see the Action.
 */
final class CancelActiveTripRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * Why the run is being stopped: `breakdown`, `accident`, `emergency` or `other`.
             * Required — the fee that follows depends on it (breakdown, accident and emergency
             * are treated differently from any other reason).
             */
            'reason' => ['required', 'string', Rule::enum(ActiveTripCancelReason::class)],

            /*
             * Optional: what happened, in your own words. Kept for support and for your appeal;
             * never sent to the passengers.
             */
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
