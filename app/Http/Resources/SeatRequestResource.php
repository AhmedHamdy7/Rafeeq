<?php

namespace App\Http\Resources;

use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\StandbyAvailability;
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

            /*
             * 🔴 She asked to join a run that was already moving (Feature 03 v5 §416). Published so
             * the driver's app can put it in the **separate sheet** v5 describes rather than mixed
             * into tomorrow's requests — the two need completely different attention: one is a
             * decision for this minute, with `expiresAt` three minutes out, and the other can wait
             * until she parks.
             */
            'requestedDuringRide' => (bool) $this->requested_during_ride,
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

            /*
             * The place the driver proposed instead, while it is waiting on the passenger (v5 §2).
             *
             * Absent entirely unless there is one, rather than present with nulls: a client should
             * render the counteroffer screen on the presence of this key, and a hash of nulls would
             * have it render an empty proposal for every ordinary request.
             *
             * 🔴 Only the end she actually moved appears. A counteroffer that touches the drop-off
             * leaves `pickup` null here, so the passenger is shown one decision rather than being
             * asked to re-agree to her own choice.
             */
            'counteroffer' => $this->when($this->counteroffered_at !== null, fn (): array => [
                'pickup' => $this->proposedPoint('pickup'),
                'dropoff' => $this->proposedPoint('dropoff'),
                'note' => $this->counteroffer_note,
                'at' => $this->counteroffered_at?->toIso8601String(),
                'answeredAt' => $this->counteroffer_answered_at?->toIso8601String(),
            ]),

            'introMessage' => $this->intro_message,
            'paymentType' => $this->payment_type->value,
            // A moment, not a flag: in a dispute what matters is when they agreed.
            'agreedToRulesAt' => $this->agreed_to_rules_at->toIso8601String(),
            // Set only while waiting for a seat to free up.
            'waitlistPosition' => $this->waitlist_position,

            /*
             * Whether she can ride today, on a waiting-list membership (Feature 03 v5 §8).
             *
             * 🔴 Computed against the commute's own local day rather than published as the stored
             * date, because the stored date goes stale and the switch does not: a woman who said
             * "not today" yesterday is available now, and a client rendering a raw date would have
             * to work out which timezone's midnight ended it. `true` is the normal answer, including
             * for anybody not on a waiting list at all.
             */
            'availableToday' => StandbyAvailability::isAvailableToday($this->resource),
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

    /**
     * One end of a driver's counteroffer, or null for the end she left alone.
     *
     * @param  'pickup'|'dropoff'  $kind
     * @return array<string, mixed>|null
     */
    private function proposedPoint(string $kind): ?array
    {
        if ($this->{"counteroffer_{$kind}_lat"} === null) {
            return null;
        }

        return [
            'lat' => (float) $this->{"counteroffer_{$kind}_lat"},
            'lng' => (float) $this->{"counteroffer_{$kind}_lng"},
            'label' => $this->{"counteroffer_{$kind}_label"},
        ];
    }
}
