<?php

namespace App\Domains\Trip\Support;

use App\Domains\Shared\ValueObjects\Coordinate;

/**
 * The `View Route in Google Maps` action, and the honesty about what it cannot do (Feature 03 v5 §4).
 *
 * > *"Google Maps URLs / native Maps intents have practical waypoint and URL-length limits that
 * > vary by platform. The backend/app **MUST NOT promise that an arbitrary number of stops can
 * > always be passed in one Google Maps deep link**. For an itinerary beyond the supported limit,
 * > show a clear fallback ... while preserving the full authoritative itinerary on RAFEEQ's
 * > backend."*
 *
 * 🔴 So this returns a link **or** a set of segments, and says which. The tempting alternative —
 * build the URL anyway and let Maps silently drop the waypoints past its limit — is the exact
 * failure v5 names: the driver would open a route that looks complete and is missing two
 * passengers, and she would find out when somebody was still standing on a kerb.
 *
 * 🔴 And the link is generated from the itinerary the backend ordered, never from anything a client
 * sent. v5: *"Generate URLs safely; do not treat client-provided order as trusted."* Accepting an
 * order from the client would let a request reorder a run — which decides who waits and for how
 * long — and would put arbitrary text into a URL this app asks a phone to open.
 *
 * What this does NOT claim: that Google Maps validates anything. It is a map with a route drawn on
 * it. Every rule about whether that route is acceptable — the detour, the promises to existing
 * riders, the schedule lock — was decided here before the link existed.
 */
final readonly class DirectionsLink
{
    private function __construct(
        /** Null when the itinerary is longer than one link can carry. */
        public ?string $url,
        public bool $exceedsWaypointLimit,
        public int $waypointLimit,
        /**
         * The itinerary split into consecutive links, each inside the limit, so a driver with a
         * full car can still navigate it in order. One entry when it fits.
         *
         * @var list<string>
         */
        public array $segments,
    ) {}

    /**
     * @param  list<Coordinate>  $stops  the ordered itinerary, start first and end last
     */
    public static function forStops(array $stops): self
    {
        $limit = max(1, (int) config('rafeeq.maps.max_waypoints'));

        // Fewer than two places is not a journey, and a Directions URL needs an origin and a
        // destination.
        if (count($stops) < 2) {
            return new self(url: null, exceedsWaypointLimit: false, waypointLimit: $limit, segments: []);
        }

        // Everything between the first and last stop is a waypoint, which is what the limit counts.
        $waypoints = count($stops) - 2;

        if ($waypoints <= $limit) {
            $url = self::url($stops);

            return new self(
                url: $url,
                exceedsWaypointLimit: false,
                waypointLimit: $limit,
                segments: [$url],
            );
        }

        return new self(
            url: null,
            exceedsWaypointLimit: true,
            waypointLimit: $limit,
            segments: self::segment($stops, $limit),
        );
    }

    /**
     * Splits a long itinerary into overlapping chunks.
     *
     * 🔴 Overlapping by one stop, deliberately: each segment ENDS where the next one begins, so
     * following them in order drives the whole route with no gap. Chunks that did not overlap would
     * leave the leg between one segment's last stop and the next segment's first unnavigated —
     * which is precisely the stretch between two passengers.
     *
     * @param  list<Coordinate>  $stops
     * @return list<string>
     */
    private static function segment(array $stops, int $limit): array
    {
        // Each segment carries an origin, `$limit` waypoints and a destination.
        $perSegment = $limit + 2;
        $segments = [];
        $offset = 0;

        while ($offset < count($stops) - 1) {
            $chunk = array_slice($stops, $offset, $perSegment);

            $segments[] = self::url($chunk);

            // Step to this chunk's LAST stop, not past it, so the next segment starts there.
            $offset += $perSegment - 1;
        }

        return $segments;
    }

    /**
     * A Google Maps Directions URL in the documented `api=1` form.
     *
     * The universal cross-platform URL rather than a native intent: v5 asks for the app with a web
     * fallback, and this one is handed to the Maps app when it is installed and opens in a browser
     * when it is not — which is the fallback, without the backend having to guess the platform.
     *
     * @param  list<Coordinate>  $stops
     */
    private static function url(array $stops): string
    {
        $origin = $stops[0];
        $destination = $stops[count($stops) - 1];
        $waypoints = array_slice($stops, 1, -1);

        $query = [
            'api' => '1',
            'origin' => self::pair($origin),
            'destination' => self::pair($destination),
            'travelmode' => 'driving',
        ];

        if ($waypoints !== []) {
            // Pipe-separated, as the Maps URL API specifies. `http_build_query` encodes the pipes,
            // which Maps accepts.
            $query['waypoints'] = implode('|', array_map(self::pair(...), $waypoints));
        }

        return rtrim((string) config('rafeeq.maps.directions_url'), '?').'?'.http_build_query($query);
    }

    /**
     * `lat,lng`, at six decimal places.
     *
     * Six rather than the seven the columns hold: six is about 11 cm, which is finer than any
     * phone's fix, and it keeps the URL inside the length limits v5 warns about when a car is full.
     */
    private static function pair(Coordinate $point): string
    {
        return number_format($point->lat, 6, '.', '').','.number_format($point->lng, 6, '.', '');
    }
}
