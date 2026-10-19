<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\PointSource;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Commute\Actions\SaveCommuteRouteAction;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;

/**
 * The two points a passenger must choose to ask for a seat (Feature 03 v5 §2).
 *
 * > *"To request a seat, the passenger MUST choose both a Pickup Point and a Drop-off Point. Each
 * > has map coordinates and label. They cannot be omitted, inferred from the driver's route
 * > endpoints, or silently defaulted. For each point, the passenger can (a) choose a suggested
 * > point advertised by the driver, where available, OR (b) choose a custom point on the map."*
 *
 * 🔴 **Driver suggestions never block a request.** v5 says so twice: *"absence of suggestions must
 * never block a passenger request"* and *"Never reject publishing merely because the driver
 * provided zero suggested pickup/drop-off points."* So a commute with no advertised meeting points
 * is requestable with two custom pins, and this class has no concept of a required suggestion.
 *
 * 🔴 **And the driver's own start and end are not choosable as suggestions.** v5: they are *"the
 * driver's journey endpoints, NOT automatically passenger pickup/drop-off stops"*. A passenger who
 * genuinely wants to meet at the driver's start point drops a pin there and it is recorded as
 * `custom_map` — which is the truth, because the driver never advertised that spot as somewhere she
 * would stop for a stranger.
 *
 * ⚠️ **`commute_offers.allows_custom_pickup` does NOT gate these two points, and that is a decision
 * v5 forces.** The flag means "I only stop where I said I would", and it still governs the separate
 * {@see PickupPointRequest} flow, where somebody already riding asks to
 * change where she is collected. It cannot gate the request's own points: v5 says a custom map
 * point is always one of the two ways to give either point, and that the absence of suggestions
 * must never block a request — so a driver who advertised nothing and had the flag off would have a
 * commute nobody could ask to join. What protects her instead is stronger than a flag: she reviews
 * every request and may Reject or Counteroffer, and the detour check below refuses anything past
 * the limit she set before she ever sees it.
 *
 * What is checked here, all server-side as v5 requires, and nothing accepted from the client:
 *
 * 1. the two points are not the same place
 * 2. they are the right way round for this commute's direction
 * 3. the detour they add, together, is inside the driver's own stated limit
 */
final readonly class RequestedPoints
{
    /**
     * Two pins closer together than this are the same place as far as a journey is concerned — the
     * same threshold, for the same reason, that {@see SaveCommuteRouteAction}
     * uses to refuse an origin that equals a destination.
     */
    private const int SAME_PLACE_METRES = 200;

    private function __construct(
        public Coordinate $pickup,
        public string $pickupLabel,
        public PointSource $pickupSource,
        public ?string $pickupSuggestionId,
        public Coordinate $dropoff,
        public string $dropoffLabel,
        public PointSource $dropoffSource,
        public ?string $dropoffSuggestionId,
        /** What the pair costs the driver, so the request can show her before she answers. */
        public PickupDetour $detour,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated upstream: `pickup` and `dropoff`, each either
     *                                       `{suggestionId}` or `{lat, lng, label}`
     */
    public static function fromInput(GeoQueryEngine $geo, CommuteOffer $offer, array $input): self
    {
        // Loaded once: both resolutions and the detour measurement read the commute's stops, and a
        // lazy load inside either would be an exception under strict mode.
        $offer->loadMissing('locations');

        [$pickup, $pickupLabel, $pickupSource, $pickupSuggestion] = self::resolve(
            $offer, $input['pickup'], CommuteLocationType::Pickup,
        );

        [$dropoff, $dropoffLabel, $dropoffSource, $dropoffSuggestion] = self::resolve(
            $offer, $input['dropoff'], CommuteLocationType::Dropoff,
        );

        self::assertDistinct($geo, $pickup, $dropoff);
        self::assertInTravelOrder($geo, $offer, $pickup, $dropoff);

        /*
         * Measured as a pair, in journey order, and checked against the number the driver chose to
         * say how far out of her way she will go. Measuring them separately would let each half
         * pass a limit the two together break.
         */
        $detour = PickupDetour::measureAll($geo, $offer, [$pickup, $dropoff]);
        $detour->assertWithin((int) $offer->max_detour_minutes);

        return new self(
            pickup: $pickup,
            pickupLabel: $pickupLabel,
            pickupSource: $pickupSource,
            pickupSuggestionId: $pickupSuggestion,
            dropoff: $dropoff,
            dropoffLabel: $dropoffLabel,
            dropoffSource: $dropoffSource,
            dropoffSuggestionId: $dropoffSuggestion,
            detour: $detour,
        );
    }

    /**
     * The columns these two points occupy on a seat request.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return [
            'requested_pickup_point' => $this->pickup,
            'requested_pickup_lat' => $this->pickup->lat,
            'requested_pickup_lng' => $this->pickup->lng,
            'requested_pickup_label' => $this->pickupLabel,
            'requested_pickup_source' => $this->pickupSource->value,
            'requested_pickup_suggestion_id' => $this->pickupSuggestionId,

            'requested_dropoff_point' => $this->dropoff,
            'requested_dropoff_lat' => $this->dropoff->lat,
            'requested_dropoff_lng' => $this->dropoff->lng,
            'requested_dropoff_label' => $this->dropoffLabel,
            'requested_dropoff_source' => $this->dropoffSource->value,
            'requested_dropoff_suggestion_id' => $this->dropoffSuggestionId,
        ];
    }

    /**
     * One point, from either of the two ways a passenger may give it.
     *
     * @param  array<string, mixed>  $point
     * @return array{0: Coordinate, 1: string, 2: PointSource, 3: string|null}
     */
    private static function resolve(CommuteOffer $offer, array $point, CommuteLocationType $kind): array
    {
        if (! isset($point['suggestionId'])) {
            return [
                new Coordinate((float) $point['lat'], (float) $point['lng']),
                (string) $point['label'],
                PointSource::CustomMap,
                null,
            ];
        }

        /*
         * 🔒 Found among THIS commute's advertised stops of THIS kind, rather than by id alone.
         * Without the scope, a suggestion id from another driver's commute would be accepted and
         * silently become a meeting point on a journey its owner never offered.
         *
         * Origin and destination are excluded by the type filter, deliberately: see the class note.
         */
        $suggestion = $offer->locations
            ->where('type', $kind)
            ->firstWhere('id', $point['suggestionId']);

        if (! $suggestion instanceof CommuteLocation) {
            throw DomainException::of(ErrorCode::SeatRequestSuggestionUnknown, fields: [
                // Which of the two, so the client can mark the right field. Never whether the id
                // exists elsewhere.
                $kind->value => [(string) $point['suggestionId']],
            ]);
        }

        return [
            new Coordinate((float) $suggestion->lat, (float) $suggestion->lng),
            // Her own label wins if she sent one — a client may show a nicer name than the
            // driver typed — but the driver's address is the fallback, never a blank.
            (string) ($point['label'] ?? $suggestion->address ?? ''),
            PointSource::DriverSuggestion,
            (string) $suggestion->getKey(),
        ];
    }

    private static function assertDistinct(GeoQueryEngine $geo, Coordinate $pickup, Coordinate $dropoff): void
    {
        if ($geo->distanceMeters($pickup, $dropoff)->isWithin(Distance::fromMetres(self::SAME_PLACE_METRES))) {
            throw DomainException::of(ErrorCode::SeatRequestPointsSame);
        }
    }

    /**
     * 🔴 Pickup before drop-off, along this commute's direction of travel.
     *
     * Asked by routing the journey both ways round and comparing: if collecting her at the
     * drop-off and setting her down at the pickup is materially QUICKER, then her two points are
     * the wrong way round for this commute and she is asking to travel against it. v5 §3 states
     * the invariant — *"each passenger's Pickup before that same passenger's Drop-off"* — and this
     * is the cheapest honest way to test it with the engine's existing primitives.
     *
     * A tolerance, not an exact comparison. Two routes that differ by a few seconds are the same
     * journey, and a straight-line engine or a live-traffic provider can both put a trivial
     * difference either way; refusing on that would reject requests for noise.
     */
    private static function assertInTravelOrder(
        GeoQueryEngine $geo,
        CommuteOffer $offer,
        Coordinate $pickup,
        Coordinate $dropoff,
    ): void {
        $origin = $offer->locations->firstWhere('type', CommuteLocationType::Origin);
        $destination = $offer->locations->firstWhere('type', CommuteLocationType::Destination);

        if ($origin === null || $destination === null) {
            // No route to be in order along. The same refusal `PickupDetour` gives, and for the
            // same reason: an unpublished commute has nothing to measure against.
            throw DomainException::of(ErrorCode::PickupNotOnCommute);
        }

        $from = new Coordinate((float) $origin->lat, (float) $origin->lng);
        $to = new Coordinate((float) $destination->lat, (float) $destination->lng);

        $asAsked = $geo->routeBetween($from, $to, [$pickup, $dropoff])->durationSeconds;
        $reversed = $geo->routeBetween($from, $to, [$dropoff, $pickup])->durationSeconds;

        // 60 seconds of slack: below that the two orders are the same journey.
        if ($asAsked > $reversed + 60) {
            throw DomainException::of(ErrorCode::SeatRequestPointsOutOfOrder);
        }
    }
}
