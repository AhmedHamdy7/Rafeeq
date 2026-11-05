<?php

namespace App\Http\Resources;

use App\Domains\Booking\Support\PickupEta;
use App\Domains\Booking\Support\RequestEligibility;
use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\RouteSettings;
use App\Domains\Geo\Actions\SearchPlacesAction;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Support\Polyline;
use App\Domains\Geo\Support\TrimmedRoute;
use App\Domains\Matching\Support\PassengerStanding;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A commute as a passenger reads it before asking for a seat (DISCOVER-1, handoff B-1).
 *
 * Everything the detail and request screens draw — the trimmed route, the driver's suggested
 * points with their times, the weekdays, the next days with seats left and where she stands on
 * each, the rules, the car, the driver — and none of what {@see CommuteOfferResource} gives the
 * commute's own driver: no plate, no full route before a confirmed seat, no driver's share.
 *
 * @mixin CommuteOffer
 */
final class PassengerCommuteResource extends JsonResource
{
    /**
     * @param  array<int, ScheduledTrip>  $upcoming
     */
    public function __construct(
        CommuteOffer $offer,
        private readonly array $upcoming,
        private readonly PassengerStanding $standing,
        private readonly bool $fullRoute,
    ) {
        parent::__construct($offer);
    }

    public function toArray(Request $request): array
    {
        $offer = $this->resource;
        $geo = app(GeoQueryEngine::class);
        $driver = $offer->driverProfile;

        $pickups = [];
        $dropoffs = [];
        $offsets = [];

        // foreach, not array_map: a list built this way keeps its element type in the contract.
        foreach ($offer->locations->sortBy('sequence') as $location) {
            if ($location->type !== CommuteLocationType::Pickup && $location->type !== CommuteLocationType::Dropoff) {
                continue;
            }

            $seconds = PickupEta::secondsAlong($geo, $offer, new Coordinate((float) $location->lat, (float) $location->lng));
            $offsets[(string) $location->id] = $seconds;

            if ($location->type === CommuteLocationType::Pickup) {
                $pickups[] = $this->suggestion($offer, $location, $seconds);
            } else {
                $dropoffs[] = $this->suggestion($offer, $location, $seconds);
            }
        }

        $upcomingTrips = [];

        foreach ($this->upcoming as $trip) {
            $upcomingTrips[] = [
                ...MatchResultResource::otherDay($trip, $this->standing),
                // The day's own frozen price (P-1), which a later price change does not move.
                'seatPricePiastres' => (int) $trip->seat_price_piastres,
                'pointTimes' => $this->pointTimes($trip, $offsets),
            ];
        }

        return [
            'commuteId' => $offer->id,
            'status' => strtoupper($offer->status->value),
            'commuteType' => $offer->commute_type->value,
            'direction' => $offer->direction->value,
            'audience' => $offer->audience->value,
            // Shown to everybody; only a woman may ask for a seat (C-11, `403 SEAT_REQUEST_WOMEN_ONLY`).
            'womenOnly' => $offer->audience === CommuteAudience::WomenOnly,
            'seatsTotal' => $offer->seats_total,
            'seatPricePiastres' => $offer->seat_price_piastres,
            // ⚠️ Deprecated alias (DISCOVER-15). Same value.
            'pricePerSeatPiastres' => $offer->seat_price_piastres,
            'currency' => $offer->currency,

            /*
             * Whether she may drop her own pin, and the limits it is checked against (Q1, COM-31):
             * within this many metres of the route, inside the whole trip's detour cap. Preview a
             * pin with `POST /commutes/{commute}/pickup-preview`.
             */
            'allowsCustomPickup' => (bool) $offer->allows_custom_pickup,
            'maxPointDistanceMetres' => RouteSettings::farRadiusMeters(),
            'maxDetourMinutes' => RouteSettings::maxTripDetourMinutes(),

            'schedule' => [
                // The days it RUNS: "Every Sun · Tue · Thu", "Nour drives Sun · Tue · Thu only".
                'daysMask' => (int) ($offer->schedule?->days_mask ?? 0),
                'departureTime' => $offer->schedule?->departure_time,
                'timezone' => $offer->schedule?->timezone,
                'startDate' => $offer->schedule?->start_date?->toDateString(),
                'endDate' => $offer->schedule?->end_date?->toDateString(),
            ],

            'route' => $this->route($offer),

            /*
             * The driver's suggested points (COM-03, BOOK-08), in road order, each with the id
             * a seat request takes as `{ "suggestionId": "…" }` and the time the car should pass
             * it on the commute's own clock. Optional: empty arrays when the driver gave none, and
             * her own pin is always the other way to choose (Q1).
             */
            'pickups' => $pickups,
            'dropoffs' => $dropoffs,

            /*
             * The next days it runs, each with seats left and where she stands on it — the
             * recurring request's "Seats now · Sun 1 left · Tue full (waiting list)" (F11).
             */
            'upcomingTrips' => $upcomingTrips,

            'rules' => (object) $this->rulesMap($offer),
            'requestEligibility' => RequestEligibility::for($request->user(), $offer),
            'vehicle' => [
                'make' => $offer->vehicle?->make,
                'model' => $offer->vehicle?->model,
                'colour' => LocalisedLabel::of($offer->vehicle?->colour),
                'tier' => $offer->vehicle?->tierOrDefault()->value,
                'hasAc' => (bool) $offer->vehicle?->has_ac,
            ],
            'driver' => [
                ...PersonSummary::for($driver->user, $request->user(), asDriver: true),
                'completedTrips' => $driver->completed_trips_count,
            ],
        ];
    }

    /**
     * One suggested point.
     *
     * @return array{id: string, label: string|null, lat: float, lng: float, estimatedTime: string|null}
     */
    /**
     * @param  array<string, int|null>  $offsets
     * @return array<int, array{pointId: string, estimatedAt: string, estimatedLocal: string}>
     */
    private function pointTimes(ScheduledTrip $trip, array $offsets): array
    {
        $times = [];

        foreach ($offsets as $pointId => $seconds) {
            if ($seconds === null || $trip->departure_at === null) {
                continue;
            }

            $at = CarbonImmutable::instance($trip->departure_at)->addSeconds($seconds);

            $times[] = [
                'pointId' => (string) $pointId,
                'estimatedAt' => $at->toIso8601String(),
                'estimatedLocal' => CarbonImmutable::instance($trip->departure_local)->addSeconds($seconds)->format('Y-m-d H:i:s'),
            ];
        }

        return $times;
    }

    private function suggestion(CommuteOffer $offer, CommuteLocation $location, ?int $seconds): array
    {
        $departure = $offer->schedule?->departure_time;

        return [
            'id' => $location->id,
            'label' => $location->address,
            'lat' => (float) $location->lat,
            'lng' => (float) $location->lng,
            // "Rehab Gate 2 · est. 07:05" — `HH:MM:SS` on the commute's own clock, from the usual
            // departure. Each day's own time comes with the day she picks.
            'estimatedTime' => $seconds === null || $departure === null
                ? null
                : CarbonImmutable::createFromFormat('H:i:s', $departure)->addSeconds($seconds)->format('H:i:s'),
        ];
    }

    /**
     * The route as she may see it: trimmed and named by area before she holds a confirmed seat,
     * whole after (Q2 option D, SAFE-18).
     *
     * @return array{start: array{label: string|null, lat: float, lng: float}|null, end: array{label: string|null, lat: float, lng: float}|null, distanceKm: float|null, durationMinutes: int|null, polyline: string|null, isTrimmed: bool}
     */
    private function route(CommuteOffer $offer): array
    {
        $points = $offer->route_polyline === null
            ? []
            : ($this->fullRoute
                ? (Polyline::isReadable($offer->route_polyline) ? Polyline::decode($offer->route_polyline) : [])
                : TrimmedRoute::of($offer->route_polyline));

        $start = $points[0] ?? null;
        $end = $points === [] ? null : $points[count($points) - 1];

        return [
            // Area names only ("Nasr City → Smart Village"): never the driver's own address.
            'start' => $start === null ? null : ['label' => $this->areaName($offer, CommuteLocationType::Origin, $start), 'lat' => $start->lat, 'lng' => $start->lng],
            'end' => $end === null ? null : ['label' => $this->areaName($offer, CommuteLocationType::Destination, $end), 'lat' => $end->lat, 'lng' => $end->lng],
            // The whole run's length and time — "27 km · about 52 min".
            'distanceKm' => $offer->route_distance_meters === null ? null : round($offer->route_distance_meters / 1000, 1),
            'durationMinutes' => $offer->route_duration_seconds === null ? null : (int) round($offer->route_duration_seconds / 60),
            'polyline' => $points === [] ? null : Polyline::encode($points),
            // False once she holds a confirmed seat on this commute: then the line is the whole road.
            'isTrimmed' => ! $this->fullRoute,
        ];
    }

    /**
     * What to call one end of the commute without naming the building: the district (or city) of
     * the catalogue place it was set from, else of the catalogue place nearest it.
     */
    private function areaName(CommuteOffer $offer, CommuteLocationType $type, Coordinate $near): ?string
    {
        $place = $offer->locations->firstWhere('type', $type)?->place
            ?? app(SearchPlacesAction::class)->nearest($near, Distance::fromKilometres(3));

        if ($place === null) {
            return null;
        }

        return LocalisedLabel::of($place->district ?? $place->city)
            ?? (app()->getLocale() === 'en' ? $place->name : $place->name_ar);
    }

    /**
     * @return array<string, bool>
     */
    private function rulesMap(CommuteOffer $offer): array
    {
        $map = [];

        foreach ($offer->rules as $rule) {
            $map[$rule->rule_key->value] = (bool) $rule->rule_value;
        }

        if ($offer->vehicle?->has_ac) {
            $map['ac'] = true;
        } else {
            unset($map['ac']);
        }

        return $map;
    }
}
