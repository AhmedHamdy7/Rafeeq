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
 * that is a single value: type, vehicle, direction, seats, price, audience and
 * the tolerances.
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
            'price_per_seat_piastres' => $attributes['pricePerSeatPiastres'],
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
     * Chapter 4 §5: seats offered cannot exceed vehicle capacity.
     *
     * `vehicles.seats` is the TOTAL including the driver, so what can be offered
     * is one fewer — the driver is occupying one of them.
     */
    public static function assertSeatsFitVehicle(Vehicle $vehicle, int $seatsTotal): void
    {
        $bookable = max(0, $vehicle->seats - 1);

        if ($seatsTotal < 1 || $seatsTotal > $bookable) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'seatsTotal' => [__('commute.seats.exceeds_vehicle', [
                    'seats' => $vehicle->seats,
                    'bookable' => $bookable,
                ])],
            ]);
        }
    }
}
