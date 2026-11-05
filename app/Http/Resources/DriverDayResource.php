<?php

namespace App\Http\Resources;

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Matching\Support\PassengerStanding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DriverDayResource extends JsonResource
{
    public function __construct(ScheduledTrip $trip, private readonly ?float $ratingAverage = null)
    {
        parent::__construct($trip);
    }

    public function toArray(Request $request): array
    {
        $route = JourneyCard::route($this->commuteOffer);
        $session = $this->tripSession;
        $from = $session?->departed_at ?? $session?->started_at;

        return [
            'id' => $this->id,
            'commuteId' => $this->commute_offer_id,
            'tripDate' => $this->trip_date->toDateString(),
            'departureLocal' => $this->departure_local->format('Y-m-d H:i:s'),
            'promisedArrivalLocal' => PassengerStanding::promisedArrivalLocal($this->resource),
            'status' => strtoupper($this->status->value),
            'originLabel' => $route['originLabel'],
            'destinationLabel' => $route['destinationLabel'],
            'seatsTotal' => $this->seats_total,
            'seatsTaken' => $this->seats_taken,
            'seatsAvailable' => (int) max(0, $this->seats_total - $this->seats_taken),
            'seatPricePiastres' => (int) $this->seat_price_piastres,
            'driverSharePiastres' => (int) $this->driver_share_piastres,
            'passengerCount' => (int) $this->passenger_count,
            'durationSeconds' => $from === null || $session?->completed_at === null
                ? null
                : (int) $from->diffInSeconds($session->completed_at),
            'ratingAverage' => $this->ratingAverage,
            'cancelledBy' => $this->cancelled_by === null ? null : strtoupper($this->cancelled_by),
            'passengersNotified' => (int) $this->passengers_notified,
            'expiredAt' => $this->expired_at?->toIso8601String(),
        ];
    }
}
