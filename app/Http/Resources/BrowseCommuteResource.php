<?php

namespace App\Http\Resources;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Matching\Support\PassengerStanding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduledTrip
 */
final class BrowseCommuteResource extends JsonResource
{
    private ?PassengerStanding $standing = null;

    public function withStanding(PassengerStanding $standing): self
    {
        $this->standing = $standing;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $trip = $this->resource;
        $offer = $trip->commuteOffer;
        $driver = $offer->driverProfile;
        $standing = $this->standing ?? PassengerStanding::for(null, []);

        $rules = [];

        foreach ($offer->rules as $rule) {
            $rules[$rule->rule_key->value] = (bool) $rule->rule_value;
        }

        if ($offer->vehicle?->has_ac) {
            $rules['ac'] = true;
        } else {
            unset($rules['ac']);
        }

        return [
            'commuteId' => $offer->id,
            'tripId' => $trip->id,
            'tripDate' => $trip->trip_date->toDateString(),
            'departureAt' => $trip->departure_at->toIso8601String(),
            'departureLocal' => $trip->departure_local->format('Y-m-d H:i:s'),
            'promisedArrivalAt' => $trip->promised_arrival_at?->toIso8601String(),
            'promisedArrivalLocal' => PassengerStanding::promisedArrivalLocal($trip),
            'daysMask' => (int) ($trip->commuteSchedule?->days_mask ?? 0),
            'seatsAvailable' => (int) max(0, $trip->seats_total - $trip->seats_taken),
            'seatPricePiastres' => (int) $trip->seat_price_piastres,
            'pricePerSeatPiastres' => (int) $trip->seat_price_piastres,
            'audience' => $offer->audience->value,
            ...JourneyCard::route($offer),
            'womenOnly' => $offer->audience === CommuteAudience::WomenOnly,
            'driver' => [
                ...PersonSummary::for($driver->user, $request->user(), asDriver: true),
                'completedTrips' => $driver->completed_trips_count,
            ],
            'vehicle' => [
                'make' => $offer->vehicle?->make,
                'model' => $offer->vehicle?->model,
                'colour' => LocalisedLabel::of($offer->vehicle?->colour),
                'tier' => $offer->vehicle?->tierOrDefault()->value,
                'hasAc' => (bool) $offer->vehicle?->has_ac,
            ],
            'meetingPoint' => null,
            'dropOffPoint' => null,
            'myStatus' => $standing->statusFor($trip),
            'overlapsBooking' => $standing->overlapFor($trip),
            'score' => null,
            'detourMinutes' => null,
            'tier' => null,
            'extraMinutes' => null,
            'rules' => (object) $rules,
            'otherDays' => [],
        ];
    }
}
