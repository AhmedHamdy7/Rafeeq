<?php

namespace App\Http\Resources;

use App\Domains\Booking\Enums\SeatOfferClosedReason;
use App\Domains\Booking\Enums\SeatOfferSource;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Support\PickupEta;
use App\Domains\Booking\Support\SameTimeBookings;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Matching\Support\PassengerStanding;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;
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
            'pickup' => $seatRequest === null ? null : [
                'label' => $seatRequest->requested_pickup_label,
                'estimatedLocal' => $this->pickupEstimatedLocal(),
            ],
            'dropoff' => $seatRequest === null ? null : [
                'label' => $seatRequest->requested_dropoff_label,
                'estimatedLocal' => $trip === null ? null : self::clock(PassengerStanding::promisedArrivalLocal($trip)),
            ],
            'promisedArrivalLocal' => $trip === null ? null : PassengerStanding::promisedArrivalLocal($trip),

            'source' => $this->source()->value,
            'isPreviousPassenger' => $this->isPreviousPassenger(),
            'commitment' => $seatRequest?->commitment?->value,
            'offerGroupId' => $this->source() === SeatOfferSource::DriverResume ? $this->release_event_id : null,
            'daysMask' => $this->source() === SeatOfferSource::DriverResume ? $this->groupDaysMask() : null,

            'closedReason' => $this->closedReason()?->value,
            'passedPointLabel' => $this->closedReason() === SeatOfferClosedReason::CarPassed
                ? $seatRequest?->requested_pickup_label
                : null,
            'stillWaiting' => $seatRequest !== null && $seatRequest->status === SeatRequestStatus::Waitlisted,

            /*
             * Her own booking that overlaps this seat in time (R-3) — the one "Switch Commute" would
             * replace — or null. Only while the offer is open: a closed offer replaces nothing.
             */
            'conflictingBookingId' => $trip === null || $seatRequest === null || $this->status !== SeatOfferStatus::Open
                ? null
                : SameTimeBookings::overlapping($trip, (string) $seatRequest->passenger_user_id)->first()?->id,
        ];
    }

    private function source(): SeatOfferSource
    {
        return $this->resource->source
            ?? ($this->replaces_booking_id !== null ? SeatOfferSource::Replacement : SeatOfferSource::SeatFreed);
    }

    private function closedReason(): ?SeatOfferClosedReason
    {
        if ($this->status === SeatOfferStatus::Open || $this->status === SeatOfferStatus::Accepted) {
            return null;
        }

        return $this->resource->closed_reason ?? SeatOfferClosedReason::legacy($this->status);
    }

    private function isPreviousPassenger(): bool
    {
        $trip = $this->relationLoaded('scheduledTrip') ? $this->scheduledTrip : null;
        $seatRequest = $this->relationLoaded('seatRequest') ? $this->seatRequest : null;

        if ($trip === null || $seatRequest === null) {
            return false;
        }

        return Booking::query()
            ->where('passenger_user_id', $seatRequest->passenger_user_id)
            ->where('scheduled_trip_id', '!=', $trip->getKey())
            ->whereHas('scheduledTrip', fn ($query) => $query->where('commute_offer_id', $trip->commute_offer_id))
            ->exists();
    }

    private function groupDaysMask(): ?int
    {
        $mask = 0;

        SeatOffer::query()
            ->where('release_event_id', $this->release_event_id)
            ->where('seat_request_id', $this->seat_request_id)
            ->with('scheduledTrip:id,trip_date')
            ->get(['id', 'scheduled_trip_id'])
            ->each(function (SeatOffer $offer) use (&$mask): void {
                if ($offer->scheduledTrip?->trip_date !== null) {
                    $mask |= DaysMask::bitForDate($offer->scheduledTrip->trip_date);
                }
            });

        return $mask === 0 ? null : $mask;
    }

    private function pickupEstimatedLocal(): ?string
    {
        $trip = $this->relationLoaded('scheduledTrip') ? $this->scheduledTrip : null;
        $offer = $trip?->relationLoaded('commuteOffer') ? $trip->commuteOffer : null;
        $seatRequest = $this->relationLoaded('seatRequest') ? $this->seatRequest : null;

        if ($trip === null || $offer === null || $seatRequest === null
            || $seatRequest->requested_pickup_lat === null || $seatRequest->requested_pickup_lng === null) {
            return null;
        }

        $at = PickupEta::alongRoute(
            app(GeoQueryEngine::class),
            $offer,
            $trip,
            new Coordinate((float) $seatRequest->requested_pickup_lat, (float) $seatRequest->requested_pickup_lng),
        );

        return self::clock(PickupEta::local($trip, $at));
    }

    private static function clock(?string $local): ?string
    {
        return $local === null ? null : substr($local, 11, 5);
    }
}
