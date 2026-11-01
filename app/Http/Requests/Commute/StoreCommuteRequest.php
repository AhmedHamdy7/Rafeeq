<?php

namespace App\Http\Requests\Commute;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteDirection;
use App\Domains\Commute\Enums\CommuteType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening a commute as a draft (Chapter 4 steps 1, 2, 5, 6, 7).
 *
 * ---
 *
 * Maintainer notes, kept out of the rules array because comments there are
 * published as field descriptions to the external mobile team:
 *
 * - The route and schedule have their own endpoints. Each is a whole thing that
 *   has to be validated together, and a partial update of either could describe
 *   a journey that makes no sense.
 * - `seatsTotal` is checked against the vehicle's APPROVED seats in the Action,
 *   not here: the limit depends on which vehicle was chosen, which a static rule
 *   cannot know (C-3).
 * - `pricePerSeatPiastres` is `prohibited`, not merely dropped from the list (P-1,
 *   D-0016). An app still on the old contract would otherwise send a price, get a
 *   201, and show the driver a number the server silently ignored — a refusal
 *   tells its developer on the first try.
 */
final class StoreCommuteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // `recurring` repeats on chosen weekdays until its end date;
            // `one_time` happens on a single date.
            'commuteType' => ['required', 'string', Rule::enum(CommuteType::class)],

            // Must be one of your own vehicles, approved and currently active.
            'vehicleId' => ['required', 'string', 'size:26'],

            // Which way this journey runs, so the return leg can be a separate
            // commute with its own times.
            'direction' => ['required', 'string', Rule::enum(CommuteDirection::class)],

            // Seats offered to passengers, NOT counting your own. Cannot exceed
            // the seats approved on your car (`approvedSeats` on the vehicle).
            'seatsTotal' => ['required', 'integer', 'min:1', 'max:7'],

            // Do not send. RAFEEQ computes the seat price from your route and your
            // car; sending a price is refused.
            'pricePerSeatPiastres' => ['prohibited'],

            /*
             * Not yours to send any more (C-1, COM-31): how far a passenger's point may be
             * from your route, and how much detour the whole trip may carry, are RAFEEQ's
             * limits for everybody (1.5 km; 10 minutes or 5 km). Sending either is refused.
             */
            'maxDetourMinutes' => ['prohibited'],
            'maxWalkMinutes' => ['prohibited'],

            // `women_only` restricts this commute to women. Everybody can see it,
            // marked women-only; only a woman can request a seat on it.
            'audience' => ['required', 'string', Rule::enum(CommuteAudience::class)],

            // Minimum number of verification levels a passenger must have
            // completed before they may request a seat.
            'minTrustLevel' => ['sometimes', 'integer', 'min:0', 'max:4'],

            // Whether a passenger may propose a meeting point you did not list.
            'allowsCustomPickup' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pricePerSeatPiastres.prohibited' => __('commute.price.set_by_rafeeq'),
        ];
    }
}
