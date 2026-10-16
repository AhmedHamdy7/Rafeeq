<?php

namespace App\Http\Requests\Account;

use App\Domains\Commute\Enums\CommuteRuleKey;
use App\Domains\Identity\Enums\Capability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The comfort chips a person wants remembered.
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as field descriptions in the OpenAPI
 * document, so they are written for the mobile team — binding standard #38):
 *
 * The chips are the same vocabulary a commute's rules and a search's `requiredRules` already use,
 * on purpose: saving a default is only useful if it can prefill a search, and it can only do that
 * if it speaks the same words.
 */
final class UpdateComfortPreferencesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * Which side you are answering as: `passenger` or `driver`. Required, because the same
             * chips mean different things — as a passenger you are saying what you would like, as
             * a driver what your car offers.
             */
            'mode' => ['required', 'string', Rule::enum(Capability::class)],

            /*
             * The chips you want remembered, as a list: `nonsmoking`, `quiet`, `ac`, `nofood`,
             * `luggage`, `front`. Send the whole list — it replaces what was stored.
             *
             * An empty list is a real answer ("none of them") and is kept as one. Omit the field
             * entirely and nothing changes.
             */
            /*
             * 🔴 `present`, not `required`. Laravel's `required` rejects an empty array, which
             * would make "none of them" — a real answer somebody chose — impossible to send, and
             * leave the client no way to clear a saved list.
             */
            'rules' => ['present', 'array'],

            'rules.*' => ['string', Rule::enum(CommuteRuleKey::class)],
        ];
    }
}
