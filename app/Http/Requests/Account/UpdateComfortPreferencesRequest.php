<?php

namespace App\Http\Requests\Account;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\ComfortPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a person wants the inside of the car to be like.
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as field descriptions in the OpenAPI
 * document, so they are written for the mobile team — binding standard #38):
 *
 * The same four questions mean opposite things in the two modes, which is why `mode` is required
 * rather than inferred from the active one: a passenger is describing what she wants, a driver is
 * setting rules for her own car, and submitting one as the other would quietly overwrite the wrong
 * row.
 */
final class UpdateComfortPreferencesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * Which side you are answering as: `passenger` or `driver`. Required, because the same
             * answers mean different things — as a passenger you are saying what you would like,
             * as a driver you are saying what your car allows.
             */
            'mode' => ['required', 'string', Rule::enum(Capability::class)],

            /*
             * Each answer is `yes`, `no` or `either`, and every field is optional — send only what
             * changed. `either` means no preference and is the default; it is not the same as `no`.
             *
             * As a passenger: whether you want it. As a driver: whether your car allows it.
             */
            'smoking' => ['sometimes', 'nullable', 'string', Rule::enum(ComfortPreference::class)],

            'music' => ['sometimes', 'nullable', 'string', Rule::enum(ComfortPreference::class)],

            // `yes` means happy to chat.
            'conversation' => ['sometimes', 'nullable', 'string', Rule::enum(ComfortPreference::class)],

            'airConditioning' => ['sometimes', 'nullable', 'string', Rule::enum(ComfortPreference::class)],
        ];
    }
}
