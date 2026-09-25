<?php

namespace App\Http\Resources;

use App\Domains\Matching\Models\SavedSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A remembered set of search filters.
 *
 * The signature is absent: it is an internal uniqueness key, and a client that
 * keyed on it would be reading our deduplication strategy.
 *
 * @mixin SavedSearch
 */
final class SavedSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            /*
             * Returned as saved, so the app can drop them straight back into the
             * search form.
             *
             * Described explicitly rather than handed back as `$this->filters`:
             * the column is JSON, so the generator can only say "an array of
             * something", which tells the mobile team nothing about a payload
             * they have to parse. Listing the shape also documents that a saved
             * search holds exactly what the search endpoint accepts.
             */
            'filters' => [
                'origin' => [
                    'lat' => (float) ($this->filters['origin']['lat'] ?? 0),
                    'lng' => (float) ($this->filters['origin']['lng'] ?? 0),
                ],
                'destination' => [
                    'lat' => (float) ($this->filters['destination']['lat'] ?? 0),
                    'lng' => (float) ($this->filters['destination']['lng'] ?? 0),
                ],
                'daysMask' => (int) ($this->filters['daysMask'] ?? 0),
                'arrivalWindowStart' => (string) ($this->filters['arrivalWindowStart'] ?? ''),
                'arrivalWindowEnd' => (string) ($this->filters['arrivalWindowEnd'] ?? ''),
                'maxWalkMinutes' => (int) ($this->filters['maxWalkMinutes'] ?? 0),
                'maxDetourMinutes' => (int) ($this->filters['maxDetourMinutes'] ?? 0),
            ],
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
