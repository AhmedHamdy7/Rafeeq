<?php

namespace App\Http\Resources;

use App\Domains\Matching\Models\SearchHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of her recent searches, as the chips under the search box (Feature 03 v5 §3).
 *
 * 🔴 `criteria` is returned **exactly as she sent it**, so the client re-runs a search by posting
 * the same object back to `GET /search/commutes`. A rendered summary would make the list readable
 * and useless: the one thing a recent search is for is being tapped.
 *
 * 🔒 Reachable only from her own list. There is no endpoint at any access level that reads anybody
 * else's — what is in here is where a person lives and where she works.
 *
 * @mixin SearchHistory
 */
final class RecentSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            /*
             * Origin, destination, days, the arrival window and whichever filters she set. Send it
             * back unchanged to repeat the search.
             *
             * Described field by field rather than handed back as `$this->criteria`, which is the
             * convention {@see SavedSearchResource} already follows for a
             * JSON column: the generator can only say "an array of something" about one, which
             * tells the mobile team nothing about a payload they have to parse. Writing it out also
             * documents that a recent search holds exactly what the search endpoint accepts, which
             * is the whole promise of the list.
             *
             * The shape mirrors `SearchCriteria::toArray()`, and the optional fields are absent
             * rather than null for the same reason they are there: what comes back should look like
             * what she filled in.
             */
            'criteria' => [
                'origin' => [
                    'lat' => (float) ($this->criteria['origin']['lat'] ?? 0),
                    'lng' => (float) ($this->criteria['origin']['lng'] ?? 0),
                ],
                'destination' => [
                    'lat' => (float) ($this->criteria['destination']['lat'] ?? 0),
                    'lng' => (float) ($this->criteria['destination']['lng'] ?? 0),
                ],
                'daysMask' => (int) ($this->criteria['daysMask'] ?? 0),
                'arrivalWindowStart' => (string) ($this->criteria['arrivalWindowStart'] ?? ''),
                'arrivalWindowEnd' => (string) ($this->criteria['arrivalWindowEnd'] ?? ''),
                'maxWalkMinutes' => (int) ($this->criteria['maxWalkMinutes'] ?? 0),
                'maxDetourMinutes' => (int) ($this->criteria['maxDetourMinutes'] ?? 0),
                'seatsNeeded' => (int) ($this->criteria['seatsNeeded'] ?? 1),
                'flexibilityMinutes' => (int) ($this->criteria['flexibilityMinutes'] ?? 0),
                'wantsReturnTrip' => (bool) ($this->criteria['wantsReturnTrip'] ?? false),

                // Set only if she chose them, so a client can tell "no filter" from "this filter".
                'audiencePreference' => $this->when(
                    isset($this->criteria['audiencePreference']),
                    fn (): string => (string) $this->criteria['audiencePreference'],
                ),
                // The Women-only filter, when she used it (Feature 03 C-11).
                'womenOnly' => $this->when(
                    ! empty($this->criteria['womenOnly']),
                    fn (): bool => true,
                ),
                'minRating' => $this->when(
                    isset($this->criteria['minRating']),
                    fn (): float => (float) $this->criteria['minRating'],
                ),
                'rules' => $this->when(
                    ($this->criteria['rules'] ?? []) !== [],
                    fn (): array => $this->ruleKeys(),
                ),
            ],

            // When she last ran it, which is what the list is ordered by.
            'searchedAt' => $this->searched_at?->toIso8601String(),

            // When it first appeared. Retention is measured from here, not from `searchedAt`.
            'firstSearchedAt' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The comfort rules she asked for, as a list of strings.
     *
     * A `foreach` rather than `array_map`, which loses the element type in the generated document
     * and would publish `rules` as an array of "something" — binding standard #37.
     *
     * @return list<string>
     */
    private function ruleKeys(): array
    {
        $keys = [];

        foreach ((array) ($this->criteria['rules'] ?? []) as $rule) {
            $keys[] = (string) $rule;
        }

        return $keys;
    }
}
