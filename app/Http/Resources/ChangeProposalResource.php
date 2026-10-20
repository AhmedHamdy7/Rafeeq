<?php

namespace App\Http\Resources;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ChangeProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A proposed change, as whichever side is looking at it (Feature 03 v5 §6).
 *
 * v5 asks for two different screens from the same row: the passenger compares **old against new**
 * and answers; the driver watches **who has answered**. Both need the comparison, so the shape is
 * shared and the two static builders add the half that belongs to one side.
 *
 * 🔒 The driver's roster carries each passenger's public first name and her answer, and nothing
 * else about her — the same restraint as everywhere else. The passenger's view carries no roster at
 * all: who else is on the commute, and whether they have agreed, is not hers to read. She is being
 * asked about her own morning.
 *
 * @mixin ChangeProposal
 */
final class ChangeProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'commuteId' => $this->commute_offer_id,
            'tripId' => $this->scheduled_trip_id,
            'tripDate' => $this->scheduledTrip?->trip_date?->toDateString(),
            'scope' => $this->scope->value,
            'status' => strtoupper($this->status->value),

            /*
             * 🔴 Bumped by every revision, and it is not decoration: an acceptance counts only
             * towards the version it answered. A client holding a stale version must re-read rather
             * than send an answer that will silently not count.
             */
            'version' => $this->version,

            /*
             * Old against new, as v5's editor and review screens both show it. `previous` was
             * captured when the proposal was made, so the comparison cannot drift under her.
             *
             * 🔴 Published as a fixed shape with explicit nulls rather than as the stored JSON.
             * The column is free-form so the set of proposable fields can grow without a
             * migration, but a free-form object reaches the mobile team as an untyped hole they
             * cannot generate a client from — the OpenAPI guard names each one. A field that is
             * not being changed is `null` on both sides.
             */
            'changes' => self::terms($this->changes),
            'previous' => self::terms($this->previous),

            'effectiveFrom' => $this->effective_from?->toDateString(),
            // When it stops being answerable: the affected morning's departure.
            'expiresAt' => $this->expires_at->toIso8601String(),
            'appliedAt' => $this->applied_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * One side of the comparison, as a fixed shape.
     *
     * A foreach rather than a mapped array for `pickupPoints`: a mapped array loses its element
     * type to the OpenAPI generator (standard #37), which is the same hole this method exists to
     * close.
     *
     * @param  array<string, mixed>|null  $terms
     * @return array<string, mixed>
     */
    private static function terms(?array $terms): array
    {
        $points = null;

        if (isset($terms['pickupPoints']) && is_array($terms['pickupPoints'])) {
            $points = [];

            foreach ($terms['pickupPoints'] as $point) {
                $points[] = [
                    'lat' => (float) ($point['lat'] ?? 0),
                    'lng' => (float) ($point['lng'] ?? 0),
                    'address' => isset($point['address']) ? (string) $point['address'] : null,
                ];
            }
        }

        return [
            'departureTime' => isset($terms['departureTime']) ? (string) $terms['departureTime'] : null,
            'promisedArrivalTime' => isset($terms['promisedArrivalTime']) ? (string) $terms['promisedArrivalTime'] : null,
            'pickupPoints' => $points,
        ];
    }

    /**
     * The driver's pending view: every affected passenger and where she stands.
     *
     * @param  array<int, ChangeProposal>  $proposals
     * @return list<array<string, mixed>>
     */
    public static function forDriver(array $proposals): array
    {
        $items = [];

        foreach ($proposals as $proposal) {
            $roster = [];

            foreach ($proposal->responseRoster() as $row) {
                $roster[] = [
                    'bookingId' => (string) $row['booking']->getKey(),
                    'passengerFirstName' => $row['booking']->passenger?->public_first_name,
                    /*
                     * `AWAITING_RESPONSE` is the absence of an answer, named rather than left null
                     * so the screen v5 describes can show it as a state beside `ACCEPTED` and
                     * `REJECTED` instead of a gap.
                     */
                    'response' => $row['response'] === null
                        ? 'AWAITING_RESPONSE'
                        : strtoupper($row['response']->value),
                ];
            }

            $items[] = [
                ...(new self($proposal))->toArray(request()),
                'affectedCount' => count($roster),
                'acceptedCount' => count(array_filter($roster, fn (array $r): bool => $r['response'] === 'ACCEPTED')),
                'passengers' => $roster,
            ];
        }

        return $items;
    }

    /**
     * The passenger's view: the comparison, and her own answer.
     *
     * @return array<string, mixed>
     */
    public static function forPassenger(ChangeProposal $proposal, Booking $booking): array
    {
        $mine = $proposal->approvals()
            ->where('booking_id', $booking->getKey())
            ->where('proposal_version', $proposal->version)
            ->first();

        return [
            ...(new self($proposal))->toArray(request()),

            // Which of her bookings this is about, so a passenger riding several days knows which
            // morning she is answering for.
            'bookingId' => (string) $booking->getKey(),

            'myResponse' => $mine === null ? null : strtoupper($mine->response->value),

            /*
             * 🔒 Deliberately no roster and no count of who else has agreed. She is being asked
             * about her own morning; who else is in the car, and whether they said yes, is not a
             * fact about her journey — and publishing it would turn a schedule change into social
             * pressure from people she may not have met.
             */
        ];
    }
}
