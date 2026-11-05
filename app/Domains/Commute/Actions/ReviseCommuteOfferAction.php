<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Commute\Enums\CommuteRuleKey;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteRule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\CommuteGuards;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Revising the terms of a commute — seats, vehicle, preferences, tolerances.
 *
 * Allowed after publishing, unlike the route and schedule, because each trip
 * carries a snapshot of the seat count and the automatic price it was generated
 * with, so a passenger who booked Tuesday keeps Tuesday's terms.
 *
 * 🔒 The price is not here at all (P-1, D-0016): it is computed by the server, and
 * the request refuses `pricePerSeatPiastres` outright.
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

        $vehicle = $offer->vehicle;

        if (CommuteState::isEditable($offer)) {
            $attributes = $this->draftAttributes($offer, $attributes);
        } else {
            unset($attributes['formStep']);
        }

        if (! CommuteState::isEditable($offer) && array_key_exists('vehicleId', $attributes)) {
            // Chapter 4's edge case: changing vehicle is allowed only if the
            // replacement is approved and active.
            $vehicle = CreateCommuteOfferAction::usableVehicle($offer->driverProfile, $attributes['vehicleId']);

            /*
             * C-3: the commute as it stands — every weekday's own seat count included — has to
             * fit the approved seats of the car it is moving to. Checked here because a smaller
             * replacement would otherwise leave days selling seats the car does not have.
             */
            if (! array_key_exists('seatsTotal', $attributes)) {
                CreateCommuteOfferAction::assertCommuteFitsVehicle($offer, $vehicle);
            }
        }

        if (! CommuteState::isEditable($offer) && array_key_exists('seatsTotal', $attributes)) {
            $this->assertSeatsFit($offer, $vehicle, (int) $attributes['seatsTotal']);
        }

        if (array_key_exists('audience', $attributes)) {
            CommuteGuards::assertDriverMayUseAudience((string) $offer->driver_profile_id, $attributes['audience']);
        }

        return DB::transaction(function () use ($offer, $attributes): CommuteOffer {
            $offer->fill($this->columns($attributes));
            $offer->save();

            $vehicle = $offer->vehicle()->first();

            if (array_key_exists('rules', $attributes)) {
                CommuteGuards::assertCarAllowsAc($vehicle, (bool) ($attributes['rules'][CommuteRuleKey::Ac->value] ?? false));
                $this->syncRules($offer, $attributes['rules']);
            }

            if (array_key_exists('vehicleId', $attributes)) {
                CommuteGuards::clearAcOnOffer($offer, $vehicle);
            }

            $offer->touch();

            return $offer->load('rules');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function draftAttributes(CommuteOffer $offer, array $attributes): array
    {
        $vehicle = array_key_exists('vehicleId', $attributes)
            ? CreateCommuteOfferAction::draftVehicle($offer->driverProfile, $attributes['vehicleId'])
            : $offer->vehicle;

        if (array_key_exists('seatsTotal', $attributes) || array_key_exists('vehicleId', $attributes)) {
            $attributes['seatsTotal'] = CreateCommuteOfferAction::draftSeats(
                $vehicle,
                $attributes['seatsTotal'] ?? $offer->seats_total,
            );
        }

        if (array_key_exists('vehicleId', $attributes) && $vehicle?->approved_seats !== null) {
            foreach ($offer->weekdaySchedules()->whereNotNull('seats_total')->pluck('seats_total') as $daySeats) {
                CreateCommuteOfferAction::assertSeatsFitVehicle($vehicle, (int) $daySeats);
            }
        }

        return $attributes;
    }

    /**
     * The new seat count has to hold for every FUTURE day that is still
     * scheduled. Past trips keep their own snapshot and are none of this
     * change's business.
     */
    private function assertSeatsFit(CommuteOffer $offer, Vehicle $vehicle, int $seatsTotal): void
    {
        CreateCommuteOfferAction::assertSeatsFitVehicle($vehicle, $seatsTotal);

        foreach ($offer->weekdaySchedules()->whereNotNull('seats_total')->pluck('seats_total') as $daySeats) {
            CreateCommuteOfferAction::assertSeatsFitVehicle($vehicle, (int) $daySeats);
        }

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
            'audience' => 'audience',
            'minTrustLevel' => 'min_trust_level',
            'allowsCustomPickup' => 'allows_custom_pickup',
            'formStep' => 'form_step',
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
