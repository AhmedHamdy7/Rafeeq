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
 * - `seatsTotal` is checked against the vehicle in the Action, not here: the
 *   limit depends on which vehicle was chosen, which a static rule cannot know.
 * - The price ceiling is what stops the platform being used as an unlicensed
 *   taxi service. A commute is shared cost, not a fare.
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
            // your vehicle's capacity minus one.
            'seatsTotal' => ['required', 'integer', 'min:1', 'max:7'],

            // Contribution per passenger, in piastres (100 piastres = 1 EGP).
            'pricePerSeatPiastres' => [
                'required', 'integer',
                'min:'.config('rafeeq.commute.min_price_piastres'),
                'max:'.config('rafeeq.commute.max_price_piastres'),
            ],

            // How far out of your way you will go for a pickup. Also the
            // tolerance used to check that pickup points are along your route.
            'maxDetourMinutes' => ['required', 'integer', 'min:0', 'max:30'],

            // How far you expect a passenger to walk to meet you.
            'maxWalkMinutes' => ['required', 'integer', 'min:0', 'max:30'],

            // `women_only` restricts this commute to women. It is applied as a
            // hard filter, so an ineligible passenger never sees it at all.
            'audience' => ['required', 'string', Rule::enum(CommuteAudience::class)],

            // Minimum number of verification levels a passenger must have
            // completed before they may request a seat.
            'minTrustLevel' => ['sometimes', 'integer', 'min:0', 'max:4'],

            // Whether a passenger may propose a meeting point you did not list.
            'allowsCustomPickup' => ['sometimes', 'boolean'],
        ];
    }
}
