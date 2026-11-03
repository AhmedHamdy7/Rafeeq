<?php

namespace App\Http\Requests\Matching;

use Illuminate\Validation\Validator;

/**
 * Editing a saved ride request, or pausing it (Feature 03 S-4, BOOK-06, decision C12).
 *
 * Two shapes, one endpoint:
 *
 * - `{ "paused": true }` alone pauses (or `false` resumes) and changes nothing else;
 * - the whole request again — exactly what `POST /commute-demands` takes — replaces it, with
 *   `paused` optional beside it.
 *
 * Replaced rather than patched field by field, for the same reason the schedule is: a ride request
 * is one thing, and half of an old one stitched to half of a new one matches journeys nobody asked
 * for.
 */
final class UpdateCommuteDemandRequest extends CommuteDemandRequest
{
    public function rules(): array
    {
        $paused = [
            /*
             * `true` stops notifications for this request until you send `false`. A paused request
             * keeps everything it had and is matched again from the moment it is resumed.
             */
            'paused' => ['sometimes', 'boolean'],
        ];

        // Just the switch: nothing else is required.
        if (! $this->has('origin')) {
            return $paused;
        }

        // The same rules as a new request — one source, so the two cannot drift.
        return [...parent::rules(), ...$paused];
    }

    public function withValidator(Validator $validator): void
    {
        if ($this->replacesCriteria()) {
            parent::withValidator($validator);
        }
    }

    public function replacesCriteria(): bool
    {
        return $this->has('origin');
    }
}
