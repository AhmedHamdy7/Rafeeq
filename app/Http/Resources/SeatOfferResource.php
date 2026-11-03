<?php

namespace App\Http\Resources;

use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Support\SameTimeBookings;
use App\Domains\Matching\Support\PassengerStanding;
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
    /**
     * What the card and the detail draw (TRIPS-13), loaded once for the page.
     *
     * @return array<int, string>
     */
    public static function relations(): array
    {
        return [
            'scheduledTrip.commuteOffer.locations',
            'scheduledTrip.commuteOffer.vehicle',
            'scheduledTrip.commuteOffer.driverProfile.user.stats',
            'scheduledTrip.commuteOffer.driverProfile.user.verifications',
            'seatRequest',
        ];
    }

    public function toArray(Request $request): array
    {
        $trip = $this->relationLoaded('scheduledTrip') ? $this->scheduledTrip : null;
        $offer = $trip?->relationLoaded('commuteOffer') ? $trip->commuteOffer : null;
        $seatRequest = $this->relationLoaded('seatRequest') ? $this->seatRequest : null;

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
                'pricePiastres' => (int) $this->scheduledTrip->seat_price_piastres,
            ]),

            /*
             * TRIPS-13: the rest of the card — whose car, which road, where she gets in and out, and
             * when she arrives. Null when the relations were not loaded (the accept and decline
             * answers). 🔒 The driver is the same `PersonSummary` every passenger card uses: a first
             * name, never a full name, a phone, a user id or a plate.
             */
            'originLabel' => $offer === null ? null : JourneyCard::route($offer)['originLabel'],
            'destinationLabel' => $offer === null ? null : JourneyCard::route($offer)['destinationLabel'],
            'driver' => $offer?->driverProfile?->user === null ? null
                : PersonSummary::for($offer->driverProfile->user, $request->user(), asDriver: true),
            'vehicle' => $offer?->vehicle === null ? null : [
                'make' => $offer->vehicle->make,
                'model' => $offer->vehicle->model,
                'colour' => LocalisedLabel::of($offer->vehicle->colour),
            ],
            'pickup' => $seatRequest === null ? null : ['label' => $seatRequest->requested_pickup_label],
            'dropoff' => $seatRequest === null ? null : ['label' => $seatRequest->requested_dropoff_label],
            'promisedArrivalLocal' => $trip === null ? null : PassengerStanding::promisedArrivalLocal($trip),

            /*
             * Her own booking that overlaps this seat in time (R-3) — the one "Switch Commute" would
             * replace — or null. Only while the offer is open: a closed offer replaces nothing.
             */
            'conflictingBookingId' => $trip === null || $seatRequest === null || $this->status !== SeatOfferStatus::Open
                ? null
                : SameTimeBookings::overlapping($trip, (string) $seatRequest->passenger_user_id)->first()?->id,
        ];
    }
}
