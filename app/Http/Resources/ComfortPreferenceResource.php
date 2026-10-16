<?php

namespace App\Http\Resources;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Models\UserPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The comfort chips somebody has saved, both modes at once.
 *
 * A Resource rather than an array built in the controller, because that is the shape Scramble can
 * actually type: an annotated private method here publishes `string[]`, while the same annotation
 * on a controller helper published `mixed` and the mobile team cannot generate a client from that.
 *
 * @mixin UserPreference
 */
final class ComfortPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'passenger' => [
                'chosen' => $this->hasChosen(Capability::Passenger),
                'rules' => $this->rulesFor(Capability::Passenger),
            ],
            'driver' => [
                'chosen' => $this->hasChosen(Capability::Driver),
                'rules' => $this->rulesFor(Capability::Driver),
            ],
        ];
    }

    /**
     * 🔴 Whether she has ever answered, said out loud rather than implied by `null`.
     *
     * The distinction is real — "none of these" is an answer somebody gave, "never opened the
     * screen" is not, and only the first should ever be handed to a search. But expressing it as
     * `null` versus `[]` is a classic client bug: the two are one `?.` apart and read the same in
     * most languages. A boolean cannot be misread.
     */
    private function hasChosen(Capability $mode): bool
    {
        return $this->resource->comfortRulesFor($mode) !== null;
    }

    /**
     * @return array<int, string>
     */
    private function rulesFor(Capability $mode): array
    {
        $saved = $this->resource->comfortRulesFor($mode);

        if ($saved === null) {
            return [];
        }

        $rules = [];

        // A foreach rather than array_map: a mapped array loses its element type in the published
        // document (standard #37), which is the whole problem this class exists to solve.
        foreach ($saved as $rule) {
            $rules[] = (string) $rule;
        }

        return $rules;
    }
}
