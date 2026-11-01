<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

/**
 * Opens a commute as a draft (Chapter 4 steps 1, 2, 5, 6, 7).
 *
 * The route and schedule arrive through their own Actions, because each is a
 * whole thing that has to be validated together. What lands here is everything
 * that is a single value: type, vehicle, direction, seats, audience and the
 * tolerances.
 *
 * 🔒 Not the price. The seat price is automatic (P-1, PAY-42, D-0016): the
 * server computes it from the route and the car when days are generated, and a
 * draft has no price until it is published.
 */
final readonly class CreateCommuteOfferAction
{
    /**
     * @param  array<string, mixed>  $attributes  validated upstream
     */
    public function execute(DriverProfile $profile, array $attributes): CommuteOffer
    {
        $this->assertDriverMayPublish($profile);

        $vehicle = $this->usableVehicle($profile, $attributes['vehicleId']);

        $this->assertSeatsFitVehicle($vehicle, (int) $attributes['seatsTotal']);

        $offer = new CommuteOffer;

        $offer->fill([
            'driver_profile_id' => $profile->user_id,
            'vehicle_id' => $vehicle->id,
            'commute_type' => $attributes['commuteType'],
            'direction' => $attributes['direction'],
            'seats_total' => $attributes['seatsTotal'],
            'max_detour_minutes' => $attributes['maxDetourMinutes'],
            'max_walk_minutes' => $attributes['maxWalkMinutes'],
            'audience' => $attributes['audience'],
            'min_trust_level' => $attributes['minTrustLevel'] ?? 0,
            'allows_custom_pickup' => $attributes['allowsCustomPickup'] ?? false,
        ]);

        // Not fillable: a commute must never be able to declare itself published
        // by including a status in the request that created it.
        $offer->status = CommuteOfferStatus::Draft->value;

        $offer->save();

        return $offer;
    }

    /**
     * Chapter 4 opens by assuming "an approved driver with one active verified
     * vehicle". Checked rather than assumed, because a driver can be suspended
     * or let their licence lapse between being approved and publishing.
     */
    public static function assertDriverMayPublish(DriverProfile $profile): void
    {
        if ($profile->status !== DriverProfileStatus::Approved) {
            throw DomainException::of(ErrorCode::DriverNotEligible, fields: [
                'driver' => [strtoupper($profile->status->value)],
            ]);
        }

        if (! $profile->hasValidLicence()) {
            throw DomainException::of(ErrorCode::LicenceExpired);
        }
    }

    /**
     * Chapter 4 §2: only approved vehicles are shown, and the rule is that the
     * vehicle "must be approved and active".
     */
    public static function usableVehicle(DriverProfile $profile, string $vehicleId): Vehicle
    {
        $vehicle = $profile->vehicles()->whereKey($vehicleId)->first();

        // 404-shaped for someone else's vehicle, so a vehicle id cannot be
        // probed for existence (Bible §6, IDOR).
        if ($vehicle === null) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        if ($vehicle->verification_status !== VehicleVerificationStatus::Approved || ! $vehicle->is_active) {
            throw DomainException::of(ErrorCode::CommuteVehicleUnavailable);
        }

        return $vehicle;
    }

    /**
     * Seats offered can never exceed the seats an admin approved on the car (C-3, PROF-28, COM-30).
     *
     * 🔴 `approved_seats`, not `seats`. `seats` is what the driver typed on the vehicle form, an
     * entry for the reviewer to check (PROF-30); the approved count is the reviewer's answer — the
     * seats with working belts that may be sold — and the only number that limits a commute.
     *
     * A car nobody has reviewed has no approved seats, and is refused with its own code rather than
     * a "0 seats" message: the driver has nothing to correct, he is waiting for a review.
     */
    public static function assertSeatsFitVehicle(Vehicle $vehicle, int $seatsTotal): void
    {
        $approved = $vehicle->approved_seats;

        if ($approved === null) {
            throw DomainException::of(ErrorCode::VehicleSeatsNotApproved, fields: [
                'vehicleId' => [$vehicle->getKey()],
            ]);
        }

        if ($seatsTotal < 1 || $seatsTotal > $approved) {
            throw DomainException::of(ErrorCode::CommuteSeatsExceedApproved, fields: [
                'seatsTotal' => [__('commute.seats.exceeds_approved', ['approved' => $approved])],
                'approvedSeats' => [(string) $approved],
            ]);
        }
    }

    /**
     * The same check for a whole commute: its own seat count and every weekday that offers a
     * different one (C-3: "seats per day ≤ approved seats"). Used wherever a commute meets a car —
     * publishing, swapping the vehicle, activating a replacement, duplicating.
     */
    public static function assertCommuteFitsVehicle(CommuteOffer $offer, Vehicle $vehicle): void
    {
        self::assertSeatsFitVehicle($vehicle, (int) $offer->seats_total);

        foreach ($offer->weekdaySchedules()->whereNotNull('seats_total')->pluck('seats_total') as $seats) {
            self::assertSeatsFitVehicle($vehicle, (int) $seats);
        }
    }
}
