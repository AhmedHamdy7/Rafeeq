<?php

namespace App\Http\Resources;

use App\Domains\Booking\Support\PickupEta;
use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Support\MatchResult;
use App\Domains\Matching\Support\PassengerStanding;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One result card (Chapter 5's "Result Card").
 *
 * What is deliberately absent is the point of the design.
 *
 * The driver appears as a PUBLIC FIRST NAME and nothing else — no full name, no
 * phone number, no gender. A passenger choosing a commute needs to recognise a
 * person, not identify them; the rest is shared only once a booking exists, and
 * gender is never shared with anyone (pitfall #30).
 *
 * The route polyline is absent too. A passenger deciding between commutes needs
 * the meeting point, the time and the price. The full road a driver takes every
 * day, handed to anyone who searches, is a movement pattern nobody agreed to
 * publish.
 *
 * @mixin MatchResult
 */
final class MatchResultResource extends JsonResource
{
    /**
     * Where the person asking already stands with each day (DISCOVER-6, -7). Set for the whole page
     * by {@see page()}; a resource rendered without it reports `none` and no overlap.
     */
    private ?PassengerStanding $standing = null;

    /**
     * A page of results, with the viewer's own standing worked out once for all of them.
     *
     * Use this rather than `collection()`: `myStatus` and `overlapsBooking` are two queries for a
     * page, and a resource left to find them itself would be two queries per card.
     *
     * Returned as an ordinary resource collection: one whose items are already this resource
     * keeps them as they are, standing included, rather than wrapping each again.
     *
     * @param  array<int, MatchResult>  $results
     */
    public static function page(array $results, ?User $viewer): AnonymousResourceCollection
    {
        $days = [];

        foreach ($results as $result) {
            $days[] = $result->trip;

            foreach ($result->otherDays as $other) {
                $days[] = $other;
            }
        }

        $standing = PassengerStanding::for($viewer, $days);

        $rows = [];

        foreach ($results as $result) {
            $row = new self($result);
            $row->standing = $standing;
            $rows[] = $row;
        }

        return new AnonymousResourceCollection($rows, self::class);
    }

    public function toArray(Request $request): array
    {
        $offer = $this->offer;
        $driver = $offer->driverProfile;
        $standing = $this->standing ?? PassengerStanding::for(null, []);

        $otherDays = [];

        // foreach, not array_map: a list built this way keeps its element type in
        // the published API contract.
        foreach ($this->otherDays as $trip) {
            $otherDays[] = self::otherDay($trip, $standing);
        }

        return [
            'commuteId' => $offer->id,
            // The day this card was matched on — the soonest one that fits.
            'tripId' => $this->trip->id,
            'tripDate' => $this->trip->trip_date->toDateString(),
            'departureAt' => $this->trip->departure_at->toIso8601String(),
            'departureLocal' => $this->trip->departure_local->format('Y-m-d H:i:s'),

            /*
             * "07:00 → arrive 08:10" (DISCOVER-2, BOOK-33): the arrival the driver PROMISED at the
             * end of her route that day. Null where none was promised — not "any time". Where she
             * herself gets out is `dropOffPoint.estimatedArrivalAt`.
             */
            'promisedArrivalAt' => $this->trip->promised_arrival_at?->toIso8601String(),
            'promisedArrivalLocal' => PassengerStanding::promisedArrivalLocal($this->trip),

            /*
             * The days the commute RUNS (DISCOVER-3) — "Sun · Tue · Thu" — as the same bitmask the
             * search takes. Not the same thing as `tripDate` + `otherDays`, which are only the days
             * that fit THIS search.
             */
            'daysMask' => (int) ($this->trip->commuteSchedule?->days_mask ?? 0),
            'seatsAvailable' => (int) max(0, $this->trip->seats_total - $this->trip->seats_taken),
            // The seat price RAFEEQ computed for that day (P-1). The only money figure a
            // passenger is sent (P-3): never the driver's share, never the commission.
            'seatPricePiastres' => (int) $this->trip->seat_price_piastres,
            // ⚠️ Deprecated alias of `seatPricePiastres` (DISCOVER-15), kept because older guide
            // text named it. Same value; read `seatPricePiastres`.
            'pricePerSeatPiastres' => (int) $this->trip->seat_price_piastres,
            'audience' => $offer->audience->value,

            // Where the commute goes, end to end — the card's "Rehab → Smart Village". See
            // JourneyCard::route() on why the intermediate stops are not named.
            ...JourneyCard::route($offer),

            /*
             * 🔴 Shown to everybody (Feature 03 C-11, BOOK-31). Only an account whose gender is
             * `woman` can request a seat on it; anybody else is refused with
             * `403 SEAT_REQUEST_WOMEN_ONLY`, so the app shows the card without the request button.
             */
            'womenOnly' => $offer->audience === CommuteAudience::WomenOnly,

            /*
             * What members are meant to see, and all of it — see PersonSummary.
             *
             * `completedTrips` comes from the DRIVER PROFILE rather than from
             * `user_stats`, because the profile's counter is the one publishing keeps
             * current; the stats table is recomputed by a job that does not exist until
             * Phase 9, so reading it here would show every driver zero trips today.
             */
            'driver' => [
                ...PersonSummary::for($driver->user, $request->user(), asDriver: true),
                'completedTrips' => $driver->completed_trips_count,
            ],

            'vehicle' => [
                'make' => $offer->vehicle?->make,
                'model' => $offer->vehicle?->model,
                'colour' => LocalisedLabel::of($offer->vehicle?->colour),
                // `comfort` shows the Comfort badge (S-1, PROF-26): a higher-rated car, priced at
                // a higher km rate. Filter on it with `tier=comfort`.
                'tier' => $offer->vehicle?->tierOrDefault()->value,
                'hasAc' => (bool) $offer->vehicle?->has_ac,
            ],

            'meetingPoint' => [
                'lat' => (float) $this->meetingPoint->lat,
                'lng' => (float) $this->meetingPoint->lng,
                'address' => LocalisedLabel::location($this->meetingPoint),
                'walkMinutes' => (int) round($this->score->walkMinutes),
                /*
                 * When the car should be there that day — "Rehab Gate 2 · est. 07:05" (DISCOVER-14).
                 * The departure plus this point's share of the route's duration: an estimate, null
                 * when the commute has no readable route.
                 */
                'estimatedAt' => $this->at($this->meetingPointSeconds)?->toIso8601String(),
                'estimatedLocal' => PickupEta::local($this->trip, $this->at($this->meetingPointSeconds)),
            ],

            /*
             * Where she would get out (DISCOVER-2): the commute's drop-off point or destination
             * nearest the place she searched for, shaped like `meetingPoint`. Null only for a
             * commute with neither, which a published one always has.
             */
            'dropOffPoint' => $this->dropOffPoint === null ? null : [
                'lat' => (float) $this->dropOffPoint->lat,
                'lng' => (float) $this->dropOffPoint->lng,
                'address' => LocalisedLabel::location($this->dropOffPoint),
                'walkMinutes' => (int) round((float) $this->dropOffWalkMinutes),
                'estimatedArrivalAt' => $this->at($this->dropOffSeconds)?->toIso8601String(),
                'estimatedArrivalLocal' => PickupEta::local($this->trip, $this->at($this->dropOffSeconds)),
            ],

            /*
             * 🔴 Where she already stands with this day (DISCOVER-7): `none` · `pending_request` ·
             * `counteroffered` · `waitlisted` · `booked`, with the ids to open it. Drives the one
             * action button — never show Request Seat on a day she has already asked for.
             */
            'myStatus' => $standing->statusFor($this->trip),

            /*
             * 🔴 The seat she already holds at the same time on ANOTHER commute (DISCOVER-6,
             * BOOK-17), or null. When set, the only action is Request to Join Waiting List, and
             * the detail says why.
             */
            'overlapsBooking' => $standing->overlapFor($this->trip),

            /*
             * The breakdown, not just the total. Chapter 5 shows a match-details
             * screen, and a passenger deciding between two commutes deserves to
             * see WHY one ranked above the other rather than being handed a number
             * to trust.
             */
            'score' => [
                'total' => $this->score->total(),
                'overlap' => $this->score->overlap,
                'schedule' => $this->score->schedule,
                'detour' => $this->score->detour,
                'audience' => $this->score->audience,
                'comfort' => $this->score->comfort,
                'reliability' => $this->score->reliability,
            ],

            'detourMinutes' => round($this->score->detourMinutes, 1),
            'rules' => (object) $this->rulesMap(),
            // The other days of the same commute that also fit, so one commute is
            // one card rather than twenty near-identical ones.
            'otherDays' => $otherDays,
        ];
    }

    /**
     * One `otherDays[]` item. Shared with `GET /matches` (DISCOVER-11), whose rows carry the same
     * list, so the two cannot describe a day differently.
     *
     * @return array{id: string, tripDate: string, departureAt: string, departureLocal: string, promisedArrivalAt: string|null, promisedArrivalLocal: string|null, seatsAvailable: int, myStatus: array{kind: string, seatRequestId: string|null, bookingId: string|null}, overlapsBooking: array{bookingId: string, driverFirstName: string|null, departureLocal: string|null, arrivalLocal: string|null}|null}
     */
    public static function otherDay(ScheduledTrip $trip, PassengerStanding $standing): array
    {
        return [
            'id' => $trip->id,
            'tripDate' => $trip->trip_date->toDateString(),
            'departureAt' => $trip->departure_at->toIso8601String(),
            // The wall clock for the date chip ("Tue 20 · 07:15", DISCOVER-3). Display only.
            'departureLocal' => $trip->departure_local->format('Y-m-d H:i:s'),
            'promisedArrivalAt' => $trip->promised_arrival_at?->toIso8601String(),
            'promisedArrivalLocal' => PassengerStanding::promisedArrivalLocal($trip),
            'seatsAvailable' => (int) max(0, $trip->seats_total - $trip->seats_taken),
            // Per DATE, like the card's own: see `myStatus` and `overlapsBooking` on the card.
            'myStatus' => $standing->statusFor($trip),
            'overlapsBooking' => $standing->overlapFor($trip),
        ];
    }

    /**
     * The card's day moved on by a point's offset into the run.
     */
    private function at(?int $seconds): ?CarbonImmutable
    {
        return $seconds === null ? null : CarbonImmutable::instance($this->trip->departure_at)->addSeconds($seconds);
    }

    /**
     * @return array<string, bool>
     */
    private function rulesMap(): array
    {
        $map = [];

        foreach ($this->offer->rules as $rule) {
            $map[$rule->rule_key->value] = (bool) $rule->rule_value;
        }

        return $map;
    }
}
