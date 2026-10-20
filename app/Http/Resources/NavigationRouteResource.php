<?php

namespace App\Http\Resources;

use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Trip\Support\DirectionsLink;
use App\Domains\Trip\Support\ItineraryStop;
use App\Domains\Trip\Support\NavigationItinerary;
use Illuminate\Support\Collection;

/**
 * The run as the driver navigates it (Feature 03 v5 §3, §4).
 *
 * One shape for both endpoints, because they answer the same question about different inputs: the
 * authoritative itinerary for a day, and the PROPOSED one for a pending request. The difference is
 * carried in `status` — `CONFIRMED` or `PREVIEW_NOT_CONFIRMED` — which v5 asks for by name.
 *
 * 🔒 Driver-only, both of them. v5 §4: *"the driver can see a passenger's pending proposed points
 * only inside their own request review; the shared/official itinerary includes confirmed riders
 * only. Do not expose other passengers' private points to unrelated passengers."* A passenger
 * reading this would learn every other rider's front door and workplace, which is most of what
 * somebody needs to wait outside one.
 */
final class NavigationRouteResource
{
    /**
     * The stored, authoritative itinerary for one day.
     *
     * @param  Collection<int, NavigationStop>  $stops
     * @return array<string, mixed>
     */
    public static function confirmed(Collection $stops, ?string $polyline, ?int $distanceMetres, ?int $durationSeconds): array
    {
        $link = DirectionsLink::forStops(
            $stops->map(fn (NavigationStop $stop) => $stop->point)->filter()->values()->all()
        );

        return [
            'status' => 'CONFIRMED',
            ...self::body(
                $stops->map(fn (NavigationStop $stop): array => [
                    'id' => (string) $stop->getKey(),
                    'kind' => strtoupper($stop->kind->value),
                    'sequence' => $stop->sequence,
                    'bookingId' => $stop->booking_id,
                    // The passenger this stop is for, so the driver knows who she is waiting for
                    // rather than only where. Her public first name, as everywhere else.
                    'passengerFirstName' => $stop->booking?->passenger?->public_first_name,
                    'lat' => (float) $stop->lat,
                    'lng' => (float) $stop->lng,
                    'label' => $stop->label,
                    'etaAt' => $stop->eta_at?->toIso8601String(),
                    'legDurationSeconds' => $stop->leg_duration_seconds,
                    'legDistanceMeters' => $stop->leg_distance_meters,
                    // v5's "reached/completed flags". Null means still to come.
                    'reachedAt' => $stop->reached_at?->toIso8601String(),
                ])->all(),
                $polyline,
                $distanceMetres,
                $durationSeconds,
                $link,
            ),
        ];
    }

    /**
     * What the itinerary WOULD be if a pending request were accepted.
     *
     * 🔴 `PREVIEW_NOT_CONFIRMED`, and nothing in here has been written down. v5 §2 forbids pending
     * points from entering the confirmed route, and §5 asks for this to be "clearly marked".
     *
     * @return array<string, mixed>
     */
    public static function preview(NavigationItinerary $itinerary): array
    {
        $link = DirectionsLink::forStops($itinerary->coordinates());

        /*
         * A foreach rather than `array_map`: a mapped array loses its element type to the OpenAPI
         * generator (standard #37), and the mobile team cannot generate a client from a list of
         * `mixed`. The guard test that catches this names the exact node.
         */
        $stops = [];

        foreach ($itinerary->stops as $stop) {
            $stops[] = [
                // No id: these rows do not exist and a client must not be able to act on one.
                'id' => null,
                'kind' => strtoupper($stop->kind->value),
                'sequence' => $stop->sequence,
                'bookingId' => $stop->bookingId === ItineraryStop::PROPOSED_BOOKING_ID ? null : $stop->bookingId,
                // Marks the two stops that are the proposal rather than the existing run, so the
                // driver can see what she is being asked to add.
                'isProposed' => $stop->bookingId === ItineraryStop::PROPOSED_BOOKING_ID,
                'passengerFirstName' => null,
                'lat' => $stop->point->lat,
                'lng' => $stop->point->lng,
                'label' => $stop->label,
                'etaAt' => $stop->etaAt?->toIso8601String(),
                'legDurationSeconds' => $stop->legDurationSeconds,
                'legDistanceMeters' => $stop->legDistanceMeters,
                'reachedAt' => null,
            ];
        }

        return [
            'status' => 'PREVIEW_NOT_CONFIRMED',
            ...self::body(
                $stops,
                $itinerary->polyline,
                $itinerary->distance->metres,
                $itinerary->durationSeconds,
                $link,
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @return array<string, mixed>
     */
    private static function body(
        array $stops,
        ?string $polyline,
        ?int $distanceMetres,
        ?int $durationSeconds,
        DirectionsLink $link,
    ): array {
        return [
            'stops' => $stops,

            /*
             * v5's "2 + 2N logical points", published rather than left to the client to count —
             * its acceptance tests are written in terms of this number, and so is the driver's
             * screen ("4 stops").
             */
            'pointCount' => count($stops),

            'polyline' => $polyline,
            'distanceMeters' => $distanceMetres,
            'durationSeconds' => $durationSeconds,

            /*
             * 🔴 The external map button, and the honest answer about its limits.
             *
             * `googleMapsDirectionsUrl` is null when the itinerary carries more stops than one
             * Directions link can hold. v5: the backend "MUST NOT promise that an arbitrary number
             * of stops can always be passed in one Google Maps deep link" — so instead of a link
             * that silently drops the last two passengers, the client gets `segments` and is told
             * why.
             */
            'googleMapsDirectionsUrl' => $link->url,
            'exceedsWaypointLimit' => $link->exceedsWaypointLimit,
            'waypointLimit' => $link->waypointLimit,
            'segments' => $link->segments,
        ];
    }
}
