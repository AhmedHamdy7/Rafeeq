<?php

namespace App\Http\Resources;

use App\Domains\Matching\Models\CommuteDemand;
use Illuminate\Http\Request;
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
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => strtoupper($this->status->value),
            // The owner's own coordinates, returned to them because this is their
            // saved request and they need to see where they set it.
            'origin' => [
                'lat' => (float) $this->origin_lat,
                'lng' => (float) $this->origin_lng,
                'label' => $this->origin_label,
            ],
            'destination' => [
                'lat' => (float) $this->dest_lat,
                'lng' => (float) $this->dest_lng,
                'label' => $this->destination_label,
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
             * A MONTHLY ceiling, which is the number screen 18 asks for and the number a
             * passenger actually knows about their own budget. The per-ride ceiling the
             * scoring uses is derived from it and the committed days — see
             * `SearchCriteria::perSeatCeilingPiastres()`.
             */
            'budgetMonthlyPiastres' => $this->budget_monthly_piastres,

            // Screen 18's "Add a return ride (~5:00 PM)". Recorded; matching the evening
            // leg itself is still open (the column has been here since Phase 1).
            'wantsReturnTrip' => $this->wants_return_trip,
            // When this stops looking for a match. A request nobody matched for
            // two months is no longer what the person wants.
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
