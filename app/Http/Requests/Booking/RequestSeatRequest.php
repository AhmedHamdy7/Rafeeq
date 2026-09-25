<?php

namespace App\Http\Requests\Booking;

use App\Domains\Booking\Enums\MeetingPreference;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asking a driver for a seat (Chapter 6, and the prototype's seat-request flow).
 *
 * ---
 *
 * Maintainer notes, kept out of the rules array because comments there are
 * published as field descriptions to the external mobile team:
 *
 * - `agreedToRules` is `accepted`, so `false` is refused rather than ignored. The
 *   column behind it is a TIMESTAMP, not a boolean: in a dispute what matters is
 *   that they agreed and when.
 * - Eligibility (audience, blocks, trust level) is re-checked in the Action using
 *   the same filters as the search, because this endpoint takes an id that may
 *   have arrived any way at all.
 * - `introMessage` is stored as written. Filtering it for phone numbers and abuse
 *   is noted in the schema and belongs with the messaging work in Phase 12; doing
 *   it badly here would be worse than doing it there properly.
 */
final class RequestSeatRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // `trial` is one day, to see how it goes. `recurring` asks to join the
            // group for a pattern of days.
            'commitment' => ['required', 'string', Rule::enum(SeatRequestCommitment::class)],

            // Which day you want. Required for a trial; for a recurring request it
            // is the first day you would join.
            'scheduledTripId' => ['required_if:commitment,trial', 'nullable', 'string', 'size:26'],

            // The days you are committing to, as a bitmask: Saturday 1, Sunday 2,
            // Monday 4, Tuesday 8, Wednesday 16, Thursday 32, Friday 64.
            'requestedDaysMask' => ['required_if:commitment,recurring', 'nullable', 'integer', 'min:1', 'max:127'],

            // How many seats you need, if you are travelling with someone.
            'seats' => ['sometimes', 'integer', 'min:1', 'max:4'],

            // Where you would like to be picked up: at the commute's own gate, on
            // a street nearby, at a landmark, or somewhere you propose yourself.
            'meetingPreference' => ['required', 'string', Rule::enum(MeetingPreference::class)],

            // A known place to be picked up from, when you chose `custom`.
            'customPickupPlaceId' => ['nullable', 'string', 'size:26', 'exists:places,id'],

            // A short note to the driver, so they know who is asking.
            'introMessage' => ['nullable', 'string', 'max:500'],

            // How you intend to pay. Cash is settled in the car.
            'paymentType' => ['required', 'string', Rule::enum(PaymentType::class)],

            // You must accept the group's rules to request a seat. They are shown
            // on the commute before this point.
            'agreedToRules' => ['required', 'accepted'],
        ];
    }
}
