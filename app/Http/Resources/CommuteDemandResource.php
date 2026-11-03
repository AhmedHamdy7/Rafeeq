<?php

namespace App\Http\Resources;

use App\Domains\Geo\Models\Place;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Support\DemandMatchCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved commute request, as its OWNER sees it.
 *
 * 🔒 This Resource must never be reachable by a driver. Chapter 5 is explicit
 * twice over — "passenger demand is private", "drivers never browse passenger
 * demands" — and it is the line between Rafeeq and an auction on passengers.
 * Every route that renders this is scoped to the authenticated owner.
 *
 * @mixin CommuteDemand
 */
final class CommuteDemandResource extends JsonResource
{
    /** Preset for a whole list by {@see page()}; worked out for this one demand otherwise. */
    private ?int $matchCount = null;

    /**
     * A list of demands with their match counts in one query rather than one each.
     *
     * @param  iterable<CommuteDemand>  $demands
     */
    public static function page(iterable $demands): AnonymousResourceCollection
    {
        $demands = collect($demands);
        $counts = DemandMatchCount::for($demands->pluck('id')->all());

        $rows = [];

        foreach ($demands as $demand) {
            $row = new self($demand);
            $row->matchCount = $counts[$demand->id] ?? 0;
            $rows[] = $row;
        }

        return new AnonymousResourceCollection($rows, self::class);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => strtoupper($this->status->value),
            /*
             * The owner's own coordinates, returned to them because this is their saved request and
             * they need to see where they set it — with what to call each end (DISCOVER-9): the
             * label she sent, or the nearest catalogue place's name, and the place id when she
             * picked it from `GET /places`.
             */
            'origin' => [
                'lat' => (float) $this->origin_lat,
                'lng' => (float) $this->origin_lng,
                'label' => LocalisedLabel::place($this->loadedPlace('originPlace'), $this->origin_label),
                'placeId' => $this->origin_place_id,
            ],
            'destination' => [
                'lat' => (float) $this->dest_lat,
                'lng' => (float) $this->dest_lng,
                'label' => LocalisedLabel::place($this->loadedPlace('destinationPlace'), $this->destination_label),
                'placeId' => $this->destination_place_id,
            ],
            'commuteType' => $this->commute_type->value,
            'daysMask' => $this->days_mask,
            'arrivalWindowStart' => $this->preferred_arrival_start,
            'arrivalWindowEnd' => $this->preferred_arrival_end,
            // Screen 18's "Flexibility ± 15 min" — how far outside the window still works.
            'flexibilityMinutes' => $this->flexibility_minutes,
            'maxWalkMinutes' => $this->max_walk_minutes,
            'maxDetourMinutes' => $this->max_detour_minutes,
            'audiencePreference' => $this->audience_preference->value,

            /*
             * Her preferences — the search's own fields, sent and returned the same way
             * (DISCOVER-9): the Women-only filter, the comfort chips, and `comfort` for a Comfort car
             * (null = any car). The alerts this demand sends honour them.
             */
            'womenOnly' => (bool) $this->women_only,
            'rules' => $this->ruleKeys(),
            'tier' => $this->vehicle_tier?->value,

            /*
             * "3 matching commutes this week" (DISCOVER-10): the different commutes matched to this
             * demand that are still published and run in the next 7 days. See DemandMatchCount.
             */
            'matchCount' => $this->matchCount ?? (DemandMatchCount::for([$this->id])[$this->id] ?? 0),

            // Screen 18's "Add a return ride (~5:00 PM)". Recorded; matching the evening
            // leg itself is still open (the column has been here since Phase 1).
            'wantsReturnTrip' => $this->wants_return_trip,
            // When this stops looking for a match. A request nobody matched for
            // two months is no longer what the person wants.
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * The comfort chips she chose, as a list of strings. A `foreach` rather than `array_map`, which
     * loses the element type in the generated document (standard #37).
     *
     * @return list<string>
     */
    /**
     * @return list<string>
     */
    public static function relations(): array
    {
        return ['originPlace', 'destinationPlace'];
    }

    private function loadedPlace(string $relation): ?Place
    {
        return $this->resource->relationLoaded($relation) ? $this->resource->{$relation} : null;
    }

    private function ruleKeys(): array
    {
        $keys = [];

        foreach ((array) ($this->required_rules ?? []) as $rule) {
            $keys[] = (string) $rule;
        }

        return $keys;
    }
}
