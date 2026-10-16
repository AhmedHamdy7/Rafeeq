<?php

namespace App\Http\Resources;

use App\Domains\Booking\Models\SeatOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One offer of a freed seat, as the waiting passenger sees it.
 *
 * 🔴 There is deliberately no position, no "you are first", and no count of who else was offered
 * it. The seat is a race decided by server transaction order, and publishing her rank inside an
 * offer would read as a queue — which is the one thing a client must not tell her, because the
 * person at position 2 can and does win.
 *
 * Her position is still available on her waiting request, where it is labelled as an estimate.
 *
 * @mixin SeatOffer
 */
final class SeatOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => strtoupper($this->status->value),
            'tripId' => $this->scheduled_trip_id,
            'seatRequestId' => $this->seat_request_id,
            'offeredAt' => $this->created_at?->toIso8601String(),
            'closedAt' => $this->closed_at?->toIso8601String(),

            /*
             * The day itself, so the list is readable without a second call. A seat on Wednesday is
             * a different decision from a seat tomorrow, and she has to be able to tell which she
             * is being offered before she accepts it.
             */
            'trip' => $this->whenLoaded('scheduledTrip', fn (): array => [
                'date' => $this->scheduledTrip->trip_date?->toDateString(),
                'departureAt' => $this->scheduledTrip->departure_at?->toIso8601String(),
                'departureLocal' => $this->scheduledTrip->departure_local?->toDateTimeString(),
                'pricePiastres' => (int) $this->scheduledTrip->price_snapshot_piastres,
            ]),
        ];
    }
}
