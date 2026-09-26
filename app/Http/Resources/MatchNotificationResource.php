<?php

namespace App\Http\Resources;

use App\Domains\Matching\Models\MatchNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A commute the platform found for one of the caller's saved requests.
 *
 * Carries the same restraint as a search result: a public first name, the vehicle,
 * the price — and no full name, no phone number, no gender, no route polyline.
 *
 * @mixin MatchNotification
 */
final class MatchNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $offer = $this->commuteOffer;

        return [
            'id' => $this->id,
            'demandId' => $this->commute_demand_id,
            'commuteId' => $offer->id,
            // The score when the match was found. The commute may have changed
            // since; this records why the interruption was justified.
            'score' => $this->score,
            'audience' => $offer->audience->value,
            'pricePerSeatPiastres' => $offer->price_per_seat_piastres,

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
            'driver' => [
                'publicFirstName' => $offer->driverProfile->user->public_first_name,
                'trustLevel' => $offer->driverProfile->user->trust_level,
            ],
            'vehicle' => [
                'make' => $offer->vehicle?->make,
                'model' => $offer->vehicle?->model,
            ],
            'deliveredAt' => $this->delivered_at?->toIso8601String(),
            'clickedAt' => $this->clicked_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
