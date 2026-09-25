<?php

namespace App\Http\Resources;

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Support\CommuteChecklist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A commute as its own driver sees it.
 *
 * The bounding box is absent: it is a search index, not information, and a
 * client that keyed on it would be reading our query plan. The polyline IS
 * included, because the app draws the route it is about to publish.
 *
 * @mixin CommuteOffer
 */
final class CommuteOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => strtoupper($this->status->value),
            'commuteType' => $this->commute_type->value,
            'direction' => $this->direction->value,
            'vehicleId' => $this->vehicle_id,
            'seatsTotal' => $this->seats_total,
            'pricePerSeatPiastres' => $this->price_per_seat_piastres,
            'currency' => $this->currency,
            'maxDetourMinutes' => $this->max_detour_minutes,
            'maxWalkMinutes' => $this->max_walk_minutes,
            'audience' => $this->audience->value,
            'minTrustLevel' => $this->min_trust_level,
            'allowsCustomPickup' => $this->allows_custom_pickup,
            // Present once published: the road as the provider returned it.
            'routePolyline' => $this->route_polyline,
            'routeDistanceMeters' => $this->route_distance_meters,
            'routeDurationSeconds' => $this->route_duration_seconds,
            // Set when the journey matched a corridor the platform has named.
            // Null is normal and not a problem.
            'corridorId' => $this->corridor_id,
            'publishedAt' => $this->published_at?->toIso8601String(),
            'pausedAt' => $this->paused_at?->toIso8601String(),
            'pausedReason' => $this->paused_reason?->value,
            'archivedAt' => $this->archived_at?->toIso8601String(),
            // What still stands between this draft and being publishable.
            'missing' => CommuteChecklist::missingFor($this->resource),
            'locations' => CommuteLocationResource::collection($this->whenLoaded('locations')),
            'schedule' => new CommuteScheduleResource($this->whenLoaded('schedule')),
            'rules' => $this->rulesMap(),
            'upcomingTrips' => ScheduledTripResource::collection($this->whenLoaded('scheduledTrips')),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function rulesMap(): array
    {
        if (! $this->resource->relationLoaded('rules')) {
            return [];
        }

        $map = [];

        foreach ($this->rules as $rule) {
            $map[$rule->rule_key->value] = (bool) $rule->rule_value;
        }

        return $map;
    }
}
