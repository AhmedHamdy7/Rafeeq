<?php

namespace App\Http\Requests\Matching;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving a set of search filters to re-run later.
 *
 * ---
 *
 * Maintainer note: `filters` is stored as JSON rather than as columns because it
 * mirrors whatever the search endpoint accepts, and pinning it to columns would
 * mean a migration every time a filter is added. The signature that prevents
 * duplicates is a hash of this object, so two saves of the same filters collapse
 * regardless of the title.
 */
final class StoreSavedSearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // What to call this search in your list. Optional.
            'title' => ['nullable', 'string', 'max:150'],

            // The search parameters to remember, in the same shape as
            // `GET /v1/search/commutes` accepts.
            'filters' => ['required', 'array'],
            'filters.origin.lat' => ['required', 'numeric', 'between:-90,90'],
            'filters.origin.lng' => ['required', 'numeric', 'between:-180,180'],
            'filters.destination.lat' => ['required', 'numeric', 'between:-90,90'],
            'filters.destination.lng' => ['required', 'numeric', 'between:-180,180'],
            'filters.daysMask' => ['required', 'integer', 'min:1', 'max:127'],
            'filters.arrivalWindowStart' => ['required', 'string', 'date_format:H:i:s'],
            'filters.arrivalWindowEnd' => ['required', 'string', 'date_format:H:i:s'],
            'filters.maxWalkMinutes' => ['required', 'integer', 'min:0', 'max:45'],
            'filters.maxDetourMinutes' => ['required', 'integer', 'min:0', 'max:45'],
        ];
    }
}
