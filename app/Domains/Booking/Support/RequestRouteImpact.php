<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Actions\ApproveSeatRequestAction;
use App\Domains\Booking\Enums\SeatRequestKind;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\RouteSettings;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Matching\Support\PassengerStanding;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use Carbon\CarbonImmutable;

final readonly class RequestRouteImpact
{
    public function __construct(
        private GeoQueryEngine $geo,
        private BuildNavigationItineraryAction $itinerary,
        private ApproveSeatRequestAction $approve,
    ) {}

    public function for(SeatRequest $request, ?ScheduledTrip $day): ?array
    {
        if ($day === null || $day->departure_at === null || $request->requested_pickup_lat === null || $request->requested_dropoff_lat === null) {
            return null;
        }

        $pickup = new Coordinate((float) $request->requested_pickup_lat, (float) $request->requested_pickup_lng);
        $dropoff = new Coordinate((float) $request->requested_dropoff_lat, (float) $request->requested_dropoff_lng);

        try {
            $preview = $this->itinerary->preview($day, $pickup, $request->requested_pickup_label, $dropoff, $request->requested_dropoff_label);
            $current = $this->itinerary->currentDurationSeconds($day);
            $detour = PickupDetour::measureAll($this->geo, $day->commuteOffer, [$pickup, $dropoff]);
            $blocked = $this->blocksAccept($request);
        } catch (DomainException) {
            return null;
        }

        $arrival = CarbonImmutable::instance($day->departure_at)->addSeconds($preview->durationSeconds);

        return [
            'tripId' => $day->id,
            'tripDate' => $day->trip_date->toDateString(),
            'addedMinutes' => (int) round(max(0, $preview->durationSeconds - $current) / 60),
            'maxDetourMinutes' => RouteSettings::maxTripDetourMinutes(),
            'withinLimit' => $detour->isWithin(),
            'promisedArrivalLocal' => PassengerStanding::promisedArrivalLocal($day),
            'projectedArrivalLocal' => PickupEta::local($day, $arrival),
            'canAccept' => ! $blocked,
            'blockReason' => $blocked ? 'promised_arrival' : null,
        ];
    }

    private function blocksAccept(SeatRequest $request): bool
    {
        if ($request->kind === SeatRequestKind::WaitlistApplication) {
            return false;
        }

        return $this->approve->breaksFrozenPromise($request);
    }
}
