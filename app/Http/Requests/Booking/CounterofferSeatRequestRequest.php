<?php

namespace App\Http\Requests\Booking;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The driver proposing a different pickup, drop-off, or both (Feature 03 v5 §2).
 *
 * ---
 *
 * Maintainer notes:
 *
 * - 🔴 **At least one of the two, and either alone is valid.** A driver may be happy to collect
 *   somebody exactly where she asked and want to set her down somewhere else. A body with neither
 *   is not a counteroffer — it is an approval with extra steps — and is refused rather than
 *   treated as one.
 * - Each point takes the same two forms as a passenger's: the id of one of the driver's own
 *   advertised stops, or coordinates with a label. The id is checked against THIS commute in the
 *   Action, never by `exists:commute_locations,id`.
 * - Whether the proposed pair is usable — two different places, the right way round, inside her own
 *   detour limit — is decided server-side by the same code that judges a passenger's pair. A driver
 *   can propose something unworkable too, and the check protects the riders she has already
 *   promised as much as it protects her.
 */
final class CounterofferSeatRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Where you would rather collect her. Omit to keep the pickup she asked for.
            'pickup' => ['sometimes', 'nullable', 'array'],
            'pickup.suggestionId' => ['nullable', 'string', 'size:26'],
            'pickup.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'pickup.label' => ['nullable', 'string', 'max:255'],

            // Where you would rather set her down. Omit to keep the drop-off she asked for.
            'dropoff' => ['sometimes', 'nullable', 'array'],
            'dropoff.suggestionId' => ['nullable', 'string', 'size:26'],
            'dropoff.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff.label' => ['nullable', 'string', 'max:255'],

            // Why, in a line she will read before deciding. Optional.
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * 🔴 "Either a suggestion id or coordinates" is checked HERE rather than with
     * `required_without` on each field, and the difference is not stylistic.
     *
     * `pickup.lat => required_without:pickup.suggestionId` fires when `pickup` is not sent **at
     * all**: there is no `pickup.suggestionId` in an absent object either, so the rule demands a
     * latitude for a point nobody is proposing. A driver countering only the drop-off was refused
     * for not supplying a pickup she deliberately left alone. Both ends are optional here, which
     * is exactly the case that trap catches.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $proposed = 0;

            foreach (['pickup', 'dropoff'] as $kind) {
                $point = $this->input($kind);

                if (! is_array($point)) {
                    continue;
                }

                $proposed++;

                if (isset($point['suggestionId'])) {
                    continue;
                }

                // A pin needs both coordinates and a name: a point with no label is unreadable on
                // the screen where she decides whether to accept it.
                foreach (['lat', 'lng', 'label'] as $field) {
                    if (! isset($point[$field]) || $point[$field] === '') {
                        $validator->errors()->add(
                            "{$kind}.{$field}",
                            __('validation.required', ['attribute' => "{$kind}.{$field}"]),
                        );
                    }
                }
            }

            if ($proposed === 0) {
                $validator->errors()->add('pickup', __('validation.custom.counteroffer.required'));
            }
        });
    }
}
