<?php

namespace App\Http\Resources;

use App\Domains\Matching\Support\MatchResult;
use Illuminate\Http\Request;
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
    public function toArray(Request $request): array
    {
        $offer = $this->offer;
        $driver = $offer->driverProfile;

        $otherDays = [];

        // foreach, not array_map: a list built this way keeps its element type in
        // the published API contract.
        foreach ($this->otherDays as $trip) {
            $otherDays[] = [
                'id' => $trip->id,
                'tripDate' => $trip->trip_date->toDateString(),
                'departureAt' => $trip->departure_at->toIso8601String(),
                'seatsAvailable' => (int) max(0, $trip->seats_total - $trip->seats_taken),
            ];
        }

        return [
            'commuteId' => $offer->id,
            // The day this card was matched on — the soonest one that fits.
            'tripId' => $this->trip->id,
            'tripDate' => $this->trip->trip_date->toDateString(),
            'departureAt' => $this->trip->departure_at->toIso8601String(),
            'departureLocal' => $this->trip->departure_local->format('Y-m-d H:i:s'),
            'seatsAvailable' => (int) max(0, $this->trip->seats_total - $this->trip->seats_taken),
            'pricePerSeatPiastres' => $this->trip->price_snapshot_piastres,
            'audience' => $offer->audience->value,

            'driver' => [
                // What members are meant to see, and all of it.
                'publicFirstName' => $driver->user->public_first_name,
                'trustLevel' => $driver->user->trust_level,
                'completedTrips' => $driver->completed_trips_count,
            ],

            'vehicle' => [
                'make' => $offer->vehicle?->make,
                'model' => $offer->vehicle?->model,
                'colour' => $offer->vehicle?->colour,
            ],

            'meetingPoint' => [
                'lat' => (float) $this->meetingPoint->lat,
                'lng' => (float) $this->meetingPoint->lng,
                'address' => $this->meetingPoint->address,
                'walkMinutes' => (int) round($this->score->walkMinutes),
            ],

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
                'price' => $this->score->price,
                'reliability' => $this->score->reliability,
            ],

            'detourMinutes' => round($this->score->detourMinutes, 1),
            'rules' => $this->rulesMap(),
            // The other days of the same commute that also fit, so one commute is
            // one card rather than twenty near-identical ones.
            'otherDays' => $otherDays,
        ];
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
