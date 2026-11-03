<?php

namespace App\Http\Resources;

use App\Domains\Booking\Support\PickupEta;
use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Support\Haversine;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use App\Domains\Matching\Support\PassengerStanding;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Shared\ValueObjects\Distance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A commute the platform found for one of the caller's saved requests.
 *
 * Carries the same restraint as a search result: a public first name, the vehicle,
 * the price — and no full name, no phone number, no gender, no route polyline.
 *
 * 🔴 And, since DISCOVER-11, the same SHAPE as a search result, beside the fields it always had. A
 * Home suggestion row says "2 seats left", and tapping it opens the commute detail; neither works
 * from a score and a label. The day, the seats, the meeting and drop-off points and the other days
 * are worked out from the demand the match was found for — its origin, destination and weekdays —
 * the way the search would have worked them out had she searched for it now.
 *
 * @mixin MatchNotification
 */
final class MatchNotificationResource extends JsonResource
{
    /** How many further days to list in `otherDays[]`. */
    private const int OTHER_DAYS = 10;

    /**
     * Everything a row reads, eager-loaded. The upcoming days only: a past day is not on offer.
     *
     * @return array<int|string, mixed>
     */
    public static function relations(): array
    {
        return [
            'commuteDemand.originPlace',
            'commuteDemand.destinationPlace',
            'commuteOffer.vehicle',
            'commuteOffer.rules',
            'commuteOffer.locations.place',
            'commuteOffer.schedule',
            'commuteOffer.driverProfile.user.stats',
            'commuteOffer.driverProfile.user.verifications',
            'commuteOffer.scheduledTrips' => fn ($query) => $query
                ->where('status', ScheduledTripStatus::Scheduled->value)
                ->where('departure_at', '>', now())
                ->orderBy('departure_at'),
        ];
    }

    public static function demandTitle(?CommuteDemand $demand): ?string
    {
        if ($demand === null) {
            return null;
        }

        $origin = LocalisedLabel::place($demand->relationLoaded('originPlace') ? $demand->originPlace : null, $demand->origin_label);
        $destination = LocalisedLabel::place($demand->relationLoaded('destinationPlace') ? $demand->destinationPlace : null, $demand->destination_label);

        return $origin !== null && $destination !== null ? "{$origin} → {$destination}" : null;
    }

    private ?PassengerStanding $standing = null;

    /**
     * A page of matches, with the viewer's standing on every day worked out once.
     *
     * @param  iterable<MatchNotification>  $notifications  loaded with {@see relations()}
     */
    public static function page(iterable $notifications, ?User $viewer): AnonymousResourceCollection
    {
        $rows = [];
        $days = [];

        foreach ($notifications as $notification) {
            $row = new self($notification);
            $rows[] = $row;

            foreach ($row->days() as $day) {
                $days[] = $day;
            }
        }

        $standing = PassengerStanding::for($viewer, $days);

        foreach ($rows as $row) {
            $row->standing = $standing;
        }

        return new AnonymousResourceCollection($rows, self::class);
    }

    public function toArray(Request $request): array
    {
        // A row rendered on its own (one match opened) loads what the list loads in one go.
        $this->resource->loadMissing(self::relations());

        $offer = $this->commuteOffer;
        $days = $this->days();
        $trip = $days[0] ?? null;
        $standing = $this->standing ?? PassengerStanding::for($request->user(), $days);

        $otherDays = [];

        foreach (array_slice($days, 1, self::OTHER_DAYS) as $other) {
            $otherDays[] = MatchResultResource::otherDay($other, $standing);
        }

        $meetingPoint = $this->nearest($offer, [CommuteLocationType::Origin, CommuteLocationType::Pickup], $this->demandPoint('origin'));
        $dropOff = $this->nearest($offer, [CommuteLocationType::Dropoff, CommuteLocationType::Destination], $this->demandPoint('destination'));

        return [
            'id' => $this->id,
            'demandId' => $this->commute_demand_id,
            'demandTitle' => self::demandTitle($this->resource->relationLoaded('commuteDemand') ? $this->commuteDemand : null),
            'commuteId' => $offer->id,
            // The score when the match was found. The commute may have changed
            // since; this records why the interruption was justified.
            'score' => $this->score,
            'audience' => $offer->audience->value,
            'womenOnly' => $offer->audience === CommuteAudience::WomenOnly,
            // The commute's current automatic seat price (P-1); null only if it was never priced.
            'seatPricePiastres' => $offer->seat_price_piastres,
            // ⚠️ Deprecated alias of `seatPricePiastres` (DISCOVER-15). Same value.
            'pricePerSeatPiastres' => $offer->seat_price_piastres,

            /*
             * Where it goes and when it leaves. Without these the notification says a
             * commute was found and not which one, so the only way to tell two apart is
             * to open both — and the home screen shows the route on the card itself.
             *
             * The origin and destination only; the intermediate stops are other
             * passengers' meeting points. See JourneyCard::route().
             */
            ...JourneyCard::route($offer),
            'departureTimeLocal' => $offer->schedule?->departure_time,
            'timezone' => $offer->schedule?->timezone,

            /*
             * The match-result fields (DISCOVER-11), read exactly as on a search result.
             *
             * The day is the soonest upcoming one on the demand's own weekdays; null, with the rest,
             * when the commute has no such day generated right now.
             */
            'tripId' => $trip?->id,
            'tripDate' => $trip?->trip_date->toDateString(),
            'departureAt' => $trip?->departure_at->toIso8601String(),
            'departureLocal' => $trip?->departure_local->format('Y-m-d H:i:s'),
            'promisedArrivalAt' => $trip?->promised_arrival_at?->toIso8601String(),
            'promisedArrivalLocal' => $trip === null ? null : PassengerStanding::promisedArrivalLocal($trip),
            'daysMask' => (int) ($offer->schedule?->days_mask ?? 0),
            'seatsAvailable' => $trip === null ? null : (int) max(0, $trip->seats_total - $trip->seats_taken),
            'meetingPoint' => $this->meetingPoint($offer, $trip, $meetingPoint),
            'dropOffPoint' => $this->dropOffPoint($offer, $trip, $dropOff),
            'rules' => (object) $this->rulesMap($offer),
            'otherDays' => $otherDays,
            'myStatus' => $trip === null ? ['kind' => 'none', 'seatRequestId' => null, 'bookingId' => null] : $standing->statusFor($trip),
            'overlapsBooking' => $trip === null ? null : $standing->overlapFor($trip),

            'driver' => [
                // The same `person` as a search result's driver, plus the profile's trip count.
                ...PersonSummary::for($offer->driverProfile->user, $request->user(), asDriver: true),
                'completedTrips' => $offer->driverProfile->completed_trips_count,
            ],
            'vehicle' => [
                'make' => $offer->vehicle?->make,
                'model' => $offer->vehicle?->model,
                'colour' => LocalisedLabel::of($offer->vehicle?->colour),
                'tier' => $offer->vehicle?->tierOrDefault()->value,
                'hasAc' => (bool) $offer->vehicle?->has_ac,
            ],
            'deliveredAt' => $this->delivered_at?->toIso8601String(),
            'clickedAt' => $this->clicked_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * The commute's upcoming days on the demand's weekdays, soonest first.
     *
     * @return array<int, ScheduledTrip>
     */
    private function days(): array
    {
        $offer = $this->commuteOffer;

        if ($offer === null || ! $offer->relationLoaded('scheduledTrips')) {
            return [];
        }

        $mask = $this->commuteDemand?->days_mask;
        $days = [];

        foreach ($offer->scheduledTrips as $trip) {
            if ($mask !== null && $mask > 0 && ! DaysMask::fromBits((int) $mask)->includesDate($trip->trip_date)) {
                continue;
            }

            $days[] = $trip;
        }

        return $days;
    }

    private function demandPoint(string $end): ?Coordinate
    {
        $demand = $this->commuteDemand;

        if ($demand === null) {
            return null;
        }

        return $end === 'origin'
            ? new Coordinate((float) $demand->origin_lat, (float) $demand->origin_lng)
            : new Coordinate((float) $demand->dest_lat, (float) $demand->dest_lng);
    }

    /**
     * The commute's stop of these types nearest a point of hers.
     *
     * @param  array<int, CommuteLocationType>  $types
     * @return array{0: CommuteLocation, 1: float}|null the stop and how far it is, in metres
     */
    private function nearest(CommuteOffer $offer, array $types, ?Coordinate $to): ?array
    {
        if ($to === null) {
            return null;
        }

        $best = null;

        foreach ($offer->locations as $location) {
            if (! in_array($location->type, $types, true)) {
                continue;
            }

            $metres = Haversine::between($to, new Coordinate((float) $location->lat, (float) $location->lng))->metres;

            if ($best === null || $metres < $best[1]) {
                $best = [$location, $metres];
            }
        }

        return $best;
    }

    /**
     * Shaped like a search result's `meetingPoint`.
     *
     * @param  array{0: CommuteLocation, 1: float}|null  $nearest
     * @return array{lat: float, lng: float, address: string|null, walkMinutes: int, estimatedAt: string|null, estimatedLocal: string|null}|null
     */
    private function meetingPoint(CommuteOffer $offer, ?ScheduledTrip $trip, ?array $nearest): ?array
    {
        if ($nearest === null) {
            return null;
        }

        [$location, $metres] = $nearest;
        $at = $this->etaAt($offer, $trip, $location);

        return [
            'lat' => (float) $location->lat,
            'lng' => (float) $location->lng,
            'address' => LocalisedLabel::location($location),
            'walkMinutes' => Distance::fromMetres((int) round($metres))->asWalkTime()->minutes,
            'estimatedAt' => $at?->toIso8601String(),
            'estimatedLocal' => $trip === null ? null : PickupEta::local($trip, $at),
        ];
    }

    /**
     * Shaped like a search result's `dropOffPoint`.
     *
     * @param  array{0: CommuteLocation, 1: float}|null  $nearest
     * @return array{lat: float, lng: float, address: string|null, walkMinutes: int, estimatedArrivalAt: string|null, estimatedArrivalLocal: string|null}|null
     */
    private function dropOffPoint(CommuteOffer $offer, ?ScheduledTrip $trip, ?array $nearest): ?array
    {
        if ($nearest === null) {
            return null;
        }

        [$location, $metres] = $nearest;
        $at = $this->etaAt($offer, $trip, $location);

        return [
            'lat' => (float) $location->lat,
            'lng' => (float) $location->lng,
            'address' => LocalisedLabel::location($location),
            'walkMinutes' => Distance::fromMetres((int) round($metres))->asWalkTime()->minutes,
            'estimatedArrivalAt' => $at?->toIso8601String(),
            'estimatedArrivalLocal' => $trip === null ? null : PickupEta::local($trip, $at),
        ];
    }

    private function etaAt(CommuteOffer $offer, ?ScheduledTrip $trip, CommuteLocation $location): ?CarbonImmutable
    {
        if ($trip === null) {
            return null;
        }

        return PickupEta::alongRoute(
            app(GeoQueryEngine::class),
            $offer,
            $trip,
            new Coordinate((float) $location->lat, (float) $location->lng),
        );
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

        return $map;
    }
}
