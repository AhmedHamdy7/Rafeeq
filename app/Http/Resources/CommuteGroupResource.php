<?php

namespace App\Http\Resources;

use App\Domains\Group\Models\CommuteGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A commute group, to the people in it.
 *
 * The group is what makes Rafeeq a commute-sharing product rather than a booking
 * engine: the same people travelling together repeatedly. So this describes the
 * arrangement — what it commits to, how much notice leaving takes, what the rules
 * are — and not a single trip.
 *
 * `seatsOpen` is computed from the next upcoming day rather than read from
 * `commute_groups.seats_open`. The stored column is a denormalised counter with no
 * single writer: every approval, cancellation, absence and expiry would have to
 * remember to move it, and the first one that forgets makes it silently wrong
 * forever. "How many seats are free" is also a per-day question, which one column
 * cannot answer. One indexed lookup on the next trip gives the true number, and the
 * column is left at its default until something needs the denormalisation badly
 * enough to own it properly.
 *
 * @mixin CommuteGroup
 */
final class CommuteGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'commuteId' => $this->commute_offer_id,
            'name' => $this->name,
            'status' => strtoupper($this->status->value),
            'minCommitmentDaysPerWeek' => $this->min_commitment_days_per_week,
            // How much warning leaving the group takes, in days.
            'noticePeriodDays' => $this->notice_period_days,

            /*
             * Recomputed periodically from completed trips (Phase 9 owns the
             * arithmetic). Zero on a new group means "no rides yet", not "never on
             * time" — a client showing it as a score would be libelling a driver
             * who has not driven.
             */
            'onTimePct' => (float) $this->on_time_pct,
            'ridesTogetherCount' => $this->rides_together_count,

            // Seats free on the next day that has not departed. Null when there is
            // no upcoming day loaded to ask about. See the class note.
            'seatsOpenNextTrip' => $this->seatsOpenNextTrip(),

            'memberCount' => $this->when(
                $this->resource->relationLoaded('members'),
                fn () => $this->members->count(),
            ),

            'createdAt' => $this->created_at->toIso8601String(),

            'commute' => new CommuteOfferResource($this->whenLoaded('commuteOffer')),
            'members' => GroupMemberResource::collection($this->whenLoaded('members')),
        ];
    }

    private function seatsOpenNextTrip(): ?int
    {
        if (! $this->resource->relationLoaded('commuteOffer')
            || ! $this->commuteOffer->relationLoaded('scheduledTrips')) {
            return null;
        }

        $next = $this->commuteOffer->scheduledTrips->first();

        if ($next === null) {
            return null;
        }

        return max(0, $next->seats_total - $next->seats_taken);
    }
}
