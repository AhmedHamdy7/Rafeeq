<?php

namespace App\Http\Requests\Booking;

use App\Domains\Booking\Enums\MeetingPreference;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Support\RequestedPoints;
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
 * - 🔴 `pickup` and `dropoff` are BOTH required (Feature 03 v5 §2). Neither may be
 *   omitted, inferred from the driver's route endpoints, or defaulted. A commute
 *   that advertises no suggested points is still requestable — with two custom
 *   pins — because v5 is explicit that the absence of suggestions must never block
 *   a request.
 * - A `suggestionId` is checked against THIS commute's advertised stops in the
 *   Action, never by `exists:commute_locations,id`: an id belonging to another
 *   driver's commute would otherwise pass validation and become a meeting point on
 *   a journey its owner never offered.
 * - Direction, distinctness and the detour the pair costs are all decided
 *   server-side in {@see RequestedPoints}. Nothing
 *   about the cost of a stop is ever accepted from the person asking for it.
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

            // What kind of place you would rather meet at: the commute's own gate, a
            // street nearby, a landmark, or somewhere you propose yourself. A
            // preference, not the point itself — that is `pickup` below.
            'meetingPreference' => ['required', 'string', Rule::enum(MeetingPreference::class)],

            // A known place to be picked up from, when you chose `custom`.
            'customPickupPlaceId' => ['nullable', 'string', 'size:26', 'exists:places,id'],

            // Where you want to be collected. Required: give either the id of one of
            // the driver's suggested points, or your own coordinates and a label.
            'pickup' => ['required', 'array'],
            'pickup.suggestionId' => ['nullable', 'string', 'size:26'],
            'pickup.lat' => ['required_without:pickup.suggestionId', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required_without:pickup.suggestionId', 'numeric', 'between:-180,180'],
            'pickup.label' => ['required_without:pickup.suggestionId', 'nullable', 'string', 'max:255'],

            // Where you want to be set down. Required, on the same terms as `pickup`.
            // It is never assumed to be the end of the driver's route.
            'dropoff' => ['required', 'array'],
            'dropoff.suggestionId' => ['nullable', 'string', 'size:26'],
            'dropoff.lat' => ['required_without:dropoff.suggestionId', 'numeric', 'between:-90,90'],
            'dropoff.lng' => ['required_without:dropoff.suggestionId', 'numeric', 'between:-180,180'],
            'dropoff.label' => ['required_without:dropoff.suggestionId', 'nullable', 'string', 'max:255'],

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
