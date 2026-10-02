<?php

namespace App\Http\Requests\Rating;

use App\Domains\Rating\Enums\RatingTagValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rating the other person on a journey (screen 37).
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as the field descriptions in the
 * OpenAPI document, so they are written for the mobile team — binding standard #38):
 *
 * 🔒 **There is no `userId` and no `direction`, and there must not be.** Who a rating is about, and
 * which way round it goes, are read from the booking. A field naming the subject would be a way to
 * put stars — or a comment — against a stranger, which is the same reasoning that keeps
 * `reportedUserId` off the incident request.
 *
 * There is no `visibleAt` either. When a rating becomes readable is the platform's to decide and
 * nobody else's; it is the whole of the double-blind protection.
 */
final class SubmitRatingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // One to five. Whole stars only — there are no halves in the design.
            'stars' => ['required', 'integer', 'between:1,5'],

            /*
             * What they want to say, if anything. Optional on purpose: somebody who had an
             * uncomfortable ride may well give three stars and not want to write about it, and
             * requiring a reason is how a rating screen turns into a form people abandon.
             */
            'comment' => ['nullable', 'string', 'max:1000'],

            /*
             * Any of the six: `safe_driving` · `on_time` · `clean_car` · `great_company` ·
             * `comfortable` · `would_ride_again`. Repeats are collapsed.
             */
            'tags' => ['nullable', 'array', 'max:6'],
            'tags.*' => [Rule::enum(RatingTagValue::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rating(): array
    {
        return [
            'stars' => $this->integer('stars'),
            'comment' => $this->input('comment'),
            'tags' => $this->input('tags', []),
        ];
    }
}
