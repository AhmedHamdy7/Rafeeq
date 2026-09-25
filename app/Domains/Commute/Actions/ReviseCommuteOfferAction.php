<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Commute\Enums\CommuteRuleKey;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteRule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Revising the terms of a commute — seats, price, preferences, tolerances.
 *
 * Allowed after publishing, unlike the route and schedule, and Chapter 4 §6 says
 * why it is safe: "price changes affect only future scheduled trips". Each trip
 * carries a snapshot of the price and seat count it was generated with, so a
 * passenger who booked Tuesday keeps Tuesday's terms and a new price applies from
 * the next day generated onwards.
 *
 * Seats are the exception that needs care. Chapter 4 §5: if seats drop below what
 * is already booked, the change is blocked until the conflict is resolved —
 * otherwise a trip would have more passengers than places, and the database CHECK
 * would refuse it anyway, as a constraint violation rather than an explanation.
 */
final readonly class ReviseCommuteOfferAction
{
    /**
     * @param  array<string, mixed>  $attributes  validated upstream
     */
    public function execute(CommuteOffer $offer, array $attributes): CommuteOffer
    {
        CommuteState::assertRevisable($offer);

        if (array_key_exists('seatsTotal', $attributes)) {
            $this->assertSeatsFit($offer, (int) $attributes['seatsTotal']);
        }

        if (array_key_exists('vehicleId', $attributes)) {
            // Chapter 4's edge case: changing vehicle is allowed only if the
            // replacement is approved and active.
            CreateCommuteOfferAction::usableVehicle($offer->driverProfile, $attributes['vehicleId']);
        }

        return DB::transaction(function () use ($offer, $attributes): CommuteOffer {
            $offer->fill($this->columns($attributes));
            $offer->save();

            if (array_key_exists('rules', $attributes)) {
                $this->syncRules($offer, $attributes['rules']);
            }

            return $offer->load('rules');
        });
    }

    /**
     * The new seat count has to hold for every FUTURE day that is still
     * scheduled. Past trips keep their own snapshot and are none of this
     * change's business.
     */
    private function assertSeatsFit(CommuteOffer $offer, int $seatsTotal): void
    {
        CreateCommuteOfferAction::assertSeatsFitVehicle($offer->vehicle, $seatsTotal);

        $mostBooked = (int) ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->where('departure_at', '>', now())
            ->max('seats_taken');

        if ($seatsTotal < $mostBooked) {
            throw DomainException::of(ErrorCode::CommuteSeatsConflict, fields: [
                'seatsTotal' => [(string) $mostBooked],
            ]);
        }
    }

    /**
     * @param  array<string, bool>  $rules
     */
    private function syncRules(CommuteOffer $offer, array $rules): void
    {
        // Replaced wholesale: the set of house rules is what the passenger agreed
        // to as a whole, and a partial update would leave a rule nobody chose.
        $offer->rules()->delete();

        foreach ($rules as $key => $value) {
            $rule = new CommuteRule;

            $rule->fill([
                'commute_offer_id' => $offer->id,
                'rule_key' => CommuteRuleKey::from($key)->value,
                'rule_value' => (bool) $value,
            ]);

            $rule->save();
        }
    }

    /**
     * `status`, `published_at` and the route columns are absent on purpose: a
     * request must never be able to publish a commute or claim a route it did
     * not earn by going through the publish Action.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function columns(array $attributes): array
    {
        $map = [
            'vehicleId' => 'vehicle_id',
            'seatsTotal' => 'seats_total',
            'pricePerSeatPiastres' => 'price_per_seat_piastres',
            'maxDetourMinutes' => 'max_detour_minutes',
            'maxWalkMinutes' => 'max_walk_minutes',
            'audience' => 'audience',
            'minTrustLevel' => 'min_trust_level',
            'allowsCustomPickup' => 'allows_custom_pickup',
        ];

        $columns = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $attributes)) {
                $columns[$column] = $attributes[$input];
            }
        }

        return $columns;
    }
}
