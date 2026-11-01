<?php

namespace App\Http\Requests\Rating;

use App\Domains\Rating\Enums\RatingTagValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing your own rating while you still may (`PATCH /ratings/{rating}`, HISTORY-5).
 *
 * ---
 *
 * Maintainer notes:
 *
 * 🔴 Its own request rather than the submit's, because an edit has to tell three things apart that
 * a submission never does: a field left out (unchanged), a field sent as null (cleared), and a field
 * with a value (replaced). The shared request made `stars` mandatory on every edit and turned a
 * left-out `comment` into null — so fixing the stars silently deleted what she had written, and
 * there was no way to say "remove my comment" that differed from "leave it alone".
 *
 * Same refusals as submitting otherwise: no `userId`, no `direction`, no `visibleAt`. And on
 * cancelled-seat feedback a comment or tags are still refused (X-2); clearing them is not.
 */
final class UpdateRatingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // One to five. Leave it out to keep the stars you gave.
            'stars' => ['sometimes', 'required', 'integer', 'between:1,5'],

            /*
             * Leave it out to keep your comment; send `null` (or an empty string) to remove it; send
             * text to replace it, up to 1000 characters.
             */
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],

            /*
             * Leave it out to keep your tags; send `[]` (or `null`) to remove them all; send a list to
             * replace them. Any of `safe_driving` · `on_time` · `clean_car` · `great_company` ·
             * `comfortable` · `would_ride_again`.
             */
            'tags' => ['sometimes', 'nullable', 'array', 'max:6'],
            'tags.*' => [Rule::enum(RatingTagValue::class)],
        ];
    }

    /**
     * Only the fields she sent — which is the whole point of this class. `SubmitRatingAction::update()`
     * changes a field only when its key is present.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('stars')) {
            $changes['stars'] = $this->integer('stars');
        }

        // `has()` is true for a key sent as null, which is what makes `"comment": null` a clear.
        if ($this->has('comment')) {
            $changes['comment'] = $this->input('comment');
        }

        if ($this->has('tags')) {
            $changes['tags'] = $this->input('tags') ?? [];
        }

        return $changes;
    }
}
