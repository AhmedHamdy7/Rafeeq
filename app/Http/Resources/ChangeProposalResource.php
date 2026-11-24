<?php

namespace App\Http\Resources;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\PickupEta;
use App\Domains\Commute\Enums\ChangeProposalStatus;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use Carbon\CarbonImmutable;
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
            // Round 2, item 6 of 2026-11-24: Going / Return, so a Return's change reads "Leaves at".
            'direction' => $this->resource->loadMissing('commuteOffer')->commuteOffer?->direction->value,
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
            ...$this->comparison(),

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
            // B2, Return commutes: the promised leave time (also sent as `departureTime`).
            'promisedDepartureTime' => isset($terms['promisedDepartureTime']) ? (string) $terms['promisedDepartureTime'] : null,
            'estimatedArrivalTime' => null,
            'pickupPoints' => $points,
        ];
    }

    /**
     * `changes` and `previous`, with a Return's estimated arrival under the old and the new leave time
     * (round 3, item 1 of 2026-11-24): the end of the route here, her own drop-off on her view.
     *
     * @return array{changes: array<string, mixed>, previous: array<string, mixed>}
     */
    private function comparison(?Booking $booking = null): array
    {
        $changes = self::terms($this->changes);
        $previous = self::terms($this->previous);
        [$before, $after] = self::arrivalTimes($this->resource, $booking);

        $changes['estimatedArrivalTime'] = $after;
        $previous['estimatedArrivalTime'] = $before;

        return ['changes' => $changes, 'previous' => $previous];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function arrivalTimes(ChangeProposal $proposal, ?Booking $booking): array
    {
        $shift = $proposal->pickupShiftSeconds();
        $offer = $proposal->loadMissing('commuteOffer')->commuteOffer;
        $trip = $proposal->loadMissing('scheduledTrip')->scheduledTrip;

        if ($shift === 0 || $offer === null || ! $offer->isReturn() || $trip === null) {
            return [null, null];
        }

        $local = null;

        if ($booking !== null && $booking->scheduledTrip !== null) {
            $day = $booking->scheduledTrip;
            $local = PickupEta::local($day, PickupEta::dropoffForBooking(app(GeoQueryEngine::class), $booking, $day->navigationStops));
        }

        $local ??= TripTimes::local($trip, TripTimes::estimatedArrival($trip));

        if ($local === null) {
            return [null, null];
        }

        return self::shifted($proposal, $local, $shift);
    }

    /**
     * The time today and the time under the change, `H:i:s`. An applied proposal has already moved
     * the day, so its "now" is the new time.
     *
     * @return array{0: string, 1: string}
     */
    private static function shifted(ChangeProposal $proposal, string $local, int $shift): array
    {
        $now = CarbonImmutable::parse($local);

        if ($proposal->status === ChangeProposalStatus::Applied) {
            return [$now->subSeconds($shift)->format('H:i:s'), $now->format('H:i:s')];
        }

        return [$now->format('H:i:s'), $now->addSeconds($shift)->format('H:i:s')];
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
            $shift = $proposal->pickupShiftSeconds();

            foreach ($proposal->responseRoster() as $row) {
                $times = self::pickupTimes($proposal, $row['booking'], $shift);

                $roster[] = [
                    'bookingId' => (string) $row['booking']->getKey(),
                    'passengerFirstName' => $row['booking']->passenger?->public_first_name,
                    'tripDate' => $row['booking']->scheduledTrip?->trip_date?->toDateString(),
                    'pickupLabel' => $row['booking']->pickup_label,
                    'previousPickupLocal' => $times['previousPickupLocal'],
                    'newPickupLocal' => $times['newPickupLocal'],
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

    private static function pickupTimes(ChangeProposal $proposal, Booking $booking, int $shift): array
    {
        $trip = $booking->scheduledTrip;
        $eta = PickupEta::forBooking(app(GeoQueryEngine::class), $booking, $trip?->navigationStops);
        $local = $trip === null ? null : PickupEta::local($trip, $eta);

        if ($local === null) {
            return ['previousPickupLocal' => null, 'newPickupLocal' => null];
        }

        [$before, $after] = self::shifted($proposal, $local, $shift);

        return [
            'previousPickupLocal' => $before,
            'newPickupLocal' => $after,
        ];
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

        $booking->loadMissing(['scheduledTrip.navigationStops', 'scheduledTrip.commuteOffer']);
        $live = in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true);
        $shift = $proposal->pickupShiftSeconds();
        $times = $live ? self::pickupTimes($proposal, $booking, $shift) : null;

        return [
            ...(new self($proposal))->toArray(request()),
            ...(new self($proposal))->comparison($live ? $booking : null),

            // Round 3, item 2 of 2026-11-24: "Your pickup · 5:20 PM → 5:35 PM", the same times the
            // driver sees for her; `newPickupLocal` is null when the change doesn't move her pickup.
            'myPickup' => $times === null ? null : [
                'label' => $booking->pickup_label,
                'previousPickupLocal' => $times['previousPickupLocal'],
                'newPickupLocal' => $shift === 0 ? null : $times['newPickupLocal'],
            ],

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
