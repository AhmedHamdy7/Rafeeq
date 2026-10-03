<?php

namespace App\Http\Requests\Matching;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteRuleKey;
use App\Domains\Matching\Support\SearchCriteria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Searching for a commute (Chapter 5).
 *
 * ---
 *
 * Maintainer notes, kept out of the rules array because comments there are
 * published as field descriptions to the external mobile team:
 *
 * - `audiencePreference` here is a PREFERENCE that feeds the score. Eligibility is
 *   decided in the query from the account's own gender, never from anything sent
 *   in a request — a women-only commute excludes non-women before scoring runs.
 * - `rules` are scored as comfort rather than filtered on: a quiet commute is a
 *   preference, and excluding on it would hide otherwise strong matches.
 * - Both walk and detour limits belong to the passenger. The driver's own detour
 *   tolerance is applied separately, as a filter.
 */
final class SearchCommutesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Where you are starting from.
            'origin' => ['required', 'array'],
            'origin.lat' => ['required', 'numeric', 'between:-90,90'],
            'origin.lng' => ['required', 'numeric', 'between:-180,180'],

            // Where you need to get to.
            'destination' => ['required', 'array'],
            'destination.lat' => ['required', 'numeric', 'between:-90,90'],
            'destination.lng' => ['required', 'numeric', 'between:-180,180'],

            /*
             * Which days you need, as a bitmask: Saturday 1, Sunday 2, Monday 4,
             * Tuesday 8, Wednesday 16, Thursday 32, Friday 64. Sunday to Thursday
             * is 62.
             */
            'daysMask' => ['required', 'integer', 'min:1', 'max:127'],

            // The earliest and latest departure you would accept, on your own
            // clock. Commutes inside the window score full marks for timing;
            // outside it, the score tapers.
            'arrivalWindowStart' => ['required', 'string', 'date_format:H:i:s'],
            'arrivalWindowEnd' => ['required', 'string', 'date_format:H:i:s', 'after_or_equal:arrivalWindowStart'],

            // How far you are willing to walk to the meeting point. Commutes whose
            // nearest pickup is further away are not returned at all.
            'maxWalkMinutes' => ['required', 'integer', 'min:0', 'max:45'],

            // How much of a detour you consider reasonable for the driver to make
            // for you. Used to rank; the driver's own limit is applied separately.
            'maxDetourMinutes' => ['required', 'integer', 'min:0', 'max:45'],

            // How many seats you need. Commutes with fewer available are excluded.
            'seatsNeeded' => ['sometimes', 'integer', 'min:1', 'max:4'],

            // Set to `women_only` to rank women-only commutes highest. Whether you
            // are eligible for one is decided by your account, not by this field.
            'audiencePreference' => ['nullable', 'string', Rule::enum(CommuteAudience::class)],

            // What you would rather not pay more than per seat, in piastres.
            // What you can spend on commuting in a MONTH. The per-ride ceiling is
            // derived from it and the days you chose, so a month is the number you know.
            'budgetMonthlyPiastres' => ['nullable', 'integer', 'min:0'],

            /*
             * The lowest driver rating you will consider, 1 to 5.
             *
             * 🔴 A HARD filter: anything below it is removed, not ranked lower. But a driver who
             * has NOT BEEN RATED YET is still shown — `null` means "nobody has said anything",
             * never zero, and hiding new drivers would both starve the platform of them and tell
             * you something untrue. Label the control accordingly.
             */
            'minRating' => ['nullable', 'numeric', 'between:1,5'],

            // How much earlier or later than your window still works for you.
            'flexibilityMinutes' => ['nullable', 'integer', 'min:0', 'max:60'],

            // Whether you want an evening leg home as well.
            'wantsReturnTrip' => ['nullable', 'boolean'],

            // House preferences you would like, for example `["nonsmoking",
            // "quiet"]`. These raise a commute's score; they do not exclude.
            'rules' => ['sometimes', 'array', 'max:6'],
            'rules.*' => ['string', Rule::enum(CommuteRuleKey::class)],
        ];
    }

    public function criteria(): SearchCriteria
    {
        return SearchCriteria::fromArray($this->validated());
    }
}
