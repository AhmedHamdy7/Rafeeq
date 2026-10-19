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

            /*
             * 🔴 The two points she chose (Feature 03 v5 §2). Always present: both are required to
             * create a request, so a client never has to handle a missing one.
             *
             * 🔒 Exact, and unlike {@see BookingResource} that is right here. This payload is
             * reached from her own request list and from the driver's review of a request on her
             * own commute — v5 §4: "the driver can see a passenger's pending proposed points only
             * inside their own request review". The driver is being asked to decide whether that
             * detour is acceptable, and she cannot judge a rounded pin.
             */
            'pickup' => $this->chosenPoint('pickup'),
            'dropoff' => $this->chosenPoint('dropoff'),

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

    /**
     * One of the two points she chose, with where it came from.
     *
     * `source` travels with the coordinates because the two are different news to a driver. A point
     * she advertised herself is one she has already agreed to stop at; a pin the passenger dropped
     * is a proposal she has not seen before, and it is the one she is weighing. A screen that could
     * not tell them apart would present both as settled.
     *
     * @param  'pickup'|'dropoff'  $kind
     * @return array<string, mixed>
     */
    private function chosenPoint(string $kind): array
    {
        return [
            'lat' => (float) $this->{"requested_{$kind}_lat"},
            'lng' => (float) $this->{"requested_{$kind}_lng"},
            'label' => $this->{"requested_{$kind}_label"},
            'source' => $this->{"requested_{$kind}_source"}?->value,
            // Which advertised stop she picked, when she picked one rather than dropping a pin.
            'suggestionId' => $this->{"requested_{$kind}_suggestion_id"},
        ];
    }
}
