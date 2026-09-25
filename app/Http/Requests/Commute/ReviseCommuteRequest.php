<?php

namespace App\Http\Requests\Commute;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteRuleKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Revising the terms of a commute (Chapter 4 §5, §6, §7).
 *
 * ---
 *
 * Maintainer notes:
 *
 * - Allowed after publishing, unlike the route and schedule, because each trip
 *   carries a snapshot of the price and seat count it was generated with: a new
 *   price applies to days generated from now on, never to a day someone already
 *   booked.
 * - Lowering seats below what is already booked is refused as a conflict, not a
 *   validation error. The number is legal; the existing passengers are what make
 *   it impossible today.
 * - `commuteType` and `direction` are absent: changing either would make this a
 *   different journey, and the days already booked would no longer describe it.
 */
final class ReviseCommuteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Swap to another of your vehicles. The replacement must be approved
            // and active.
            'vehicleId' => ['sometimes', 'string', 'size:26'],

            // Seats offered, not counting your own. Cannot go below the number
            // already booked on any future day.
            'seatsTotal' => ['sometimes', 'integer', 'min:1', 'max:7'],

            // Contribution per passenger, in piastres. Applies to days generated
            // from now on; days already booked keep their original price.
            'pricePerSeatPiastres' => [
                'sometimes', 'integer',
                'min:'.config('rafeeq.commute.min_price_piastres'),
                'max:'.config('rafeeq.commute.max_price_piastres'),
            ],

            'maxDetourMinutes' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'maxWalkMinutes' => ['sometimes', 'integer', 'min:0', 'max:30'],

            // Narrowing the audience does not remove passengers who already
            // joined; it applies to who can find and request a seat from now on.
            'audience' => ['sometimes', 'string', Rule::enum(CommuteAudience::class)],
            'minTrustLevel' => ['sometimes', 'integer', 'min:0', 'max:4'],
            'allowsCustomPickup' => ['sometimes', 'boolean'],

            /*
             * House preferences, as a map of rule to whether it applies — for
             * example `{"nonsmoking": true, "quiet": true}`. Sending this
             * replaces the whole set. They are preferences a passenger agrees to,
             * not legal requirements.
             */
            'rules' => ['sometimes', 'array'],
            'rules.*' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_keys((array) $this->input('rules', [])) as $key) {
                if (CommuteRuleKey::tryFrom((string) $key) === null) {
                    $validator->errors()->add("rules.{$key}", __('validation.in', ['attribute' => 'rule']));
                }
            }
        });
    }
}
