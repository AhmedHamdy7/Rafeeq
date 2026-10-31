<?php

namespace App\Http\Requests\Commute;

use App\Domains\Commute\Enums\ChangeProposalScope;
use App\Domains\Commute\Support\RouteSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A driver asking her confirmed passengers to agree to a change (Feature 03 v5 §6).
 *
 * ---
 *
 * Maintainer notes:
 *
 * - 🔴 **One combined request.** v5's editor sends every field together because they only make
 *   sense together — a departure moved without the pickup moved can be a journey nobody can make —
 *   and there is no partial acceptance of the result. At least one field must actually change.
 * - **Price is not here, deliberately.** A confirmed seat's price never moves (v5: "Price changes
 *   only affect new bookings; snapshot agreed seat price in each booking"), so there is nothing to
 *   ask anybody about. `PATCH /commutes/{commute}` already changes it for future bookings.
 * - `pickupPoints` requires the all-future scope. The commute has one advertised route across its
 *   weekdays, so moving the stops for Tuesday alone would either change every other morning as a
 *   side effect or need a second route the model does not have. The Action refuses it.
 * - Whether anybody is actually affected, and whether another proposal is already open, are decided
 *   in the Action against the current bookings.
 */
final class ProposeCommuteChangeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The day you are proposing a change to. Must be one of this commute's future days.
            'scheduledTripId' => ['required', 'string', 'size:26'],

            // `only_this_occurrence` for one morning, `all_future_on_this_weekday` to move that
            // weekday from a date onwards.
            'scope' => ['required', 'string', Rule::enum(ChangeProposalScope::class)],

            // From when the all-future scope applies. Defaults to the day above.
            'effectiveFrom' => ['nullable', 'date'],

            // The new local departure time, as HH:MM or HH:MM:SS.
            'departureTime' => ['nullable', 'date_format:H:i,H:i:s'],

            // The new promised arrival, on the same format. Both are optional on their own; at
            // least one field in the whole request has to change something.
            'promisedArrivalTime' => ['nullable', 'date_format:H:i,H:i:s'],

            // The advertised pickup points, replaced as a whole list in the order you would reach
            // them. Requires the all-future scope.
            'pickupPoints' => ['sometimes', 'array', 'max:'.RouteSettings::maxPickupStops()],
            'pickupPoints.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickupPoints.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'pickupPoints.*.address' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $proposesSomething = $this->filled('departureTime')
                || $this->filled('promisedArrivalTime')
                || is_array($this->input('pickupPoints'));

            if (! $proposesSomething) {
                $validator->errors()->add('departureTime', __('validation.custom.change_proposal.required'));
            }
        });
    }
}
