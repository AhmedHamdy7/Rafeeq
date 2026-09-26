<?php

namespace App\Http\Resources;

use App\Domains\Booking\Models\SeatRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A seat request, shown to whichever side is looking at it.
 *
 * The passenger appears as a public first name and a trust level — the same
 * restraint as everywhere else. A driver deciding who rides in their car needs to
 * recognise a person and see what has been verified about them; they do not need
 * a full name or a phone number, and they never see gender.
 *
 * @mixin SeatRequest
 */
final class SeatRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'commuteId' => $this->commute_offer_id,
            'tripId' => $this->scheduled_trip_id,
            'status' => strtoupper($this->status->value),
            'commitment' => $this->commitment->value,
            'requestedDaysMask' => $this->requested_days_mask,
            'seats' => $this->seats,
            'meetingPreference' => $this->meeting_preference->value,
            'introMessage' => $this->intro_message,
            'paymentType' => $this->payment_type->value,
            // A moment, not a flag: in a dispute what matters is when they agreed.
            'agreedToRulesAt' => $this->agreed_to_rules_at->toIso8601String(),
            // Set only while waiting for a seat to free up.
            'waitlistPosition' => $this->waitlist_position,
            'responseNote' => $this->response_note,
            'respondedAt' => $this->responded_at?->toIso8601String(),
            // A request nobody answers expires, so it stops holding your one open
            // request for this commute.
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),

            /*
             * The driver deciding who rides in their car reads this. Screen 28 shows a
             * rating, a trip count, an on-time rate and named verification badges — a
             * bare first name is not enough to make that decision on, and a full name
             * is more than it takes.
             */
            'passenger' => $this->when(
                $this->resource->relationLoaded('passenger'),
                fn () => PersonSummary::for($this->passenger, $request->user()),
            ),
        ];
    }
}
