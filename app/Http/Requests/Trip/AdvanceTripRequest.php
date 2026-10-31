<?php

namespace App\Http\Requests\Trip;

use App\Domains\Trip\Enums\TripSessionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a run to its next state.
 *
 * ---
 *
 * Maintainer notes:
 *
 * - `Rule::enum` accepts every case the enum has, including `completed`, `cancelled`
 *   and `emergency`, which this endpoint does NOT serve — `completed` has its own
 *   endpoint because finishing a run does several other things, and the other two are
 *   not the driver's to declare here. The state machine refuses them by transition
 *   rather than by validation, so the refusal says where the run is and what it could
 *   do instead; a validation error would only say the value was not allowed.
 * - Which transitions are legal is the enum's business (`TripSessionStatus`), not a
 *   rule here. A validation rule cannot see the run's current state.
 */
final class AdvanceTripRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Where the run has got to: EN_ROUTE, AT_PICKUP or IN_PROGRESS.
            'status' => ['required', 'string', Rule::enum(TripSessionStatus::class)],

            // AT_PICKUP only: "I've arrived" for this passenger — her booking on this run. Her
            // 5-minute grace starts from the later of now and her pickup time.
            'bookingId' => ['required_if:status,at_pickup', 'nullable', 'string', 'size:26'],

            // AT_PICKUP only: where the car is now. Refused with 409 NOT_AT_PICKUP (with
            // `distanceMeters`) unless it is within 150 m of her pickup point.
            'lat' => ['required_if:status,at_pickup', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_if:status,at_pickup', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * The client sends the state in the same shape it reads it back — upper case, like
     * every other status in the API — and the enum stores lower case.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => strtolower($this->input('status'))]);
        }
    }
}
