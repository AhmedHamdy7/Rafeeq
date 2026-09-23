<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Support\DuplicateDetector;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Adding, editing and selecting a vehicle (Chapter 3 §7, §16 story 5).
 *
 * A driver may keep several vehicles and exactly one is active; commutes use
 * whichever is active at the time. That constraint is enforced by a generated
 * column plus a unique index — not by this class — so two requests racing to
 * activate different vehicles cannot both win. What this class does is make
 * the switch atomic, so there is no moment with none active either.
 */
final readonly class ManageVehicleAction
{
    /**
     * @param  array<string, mixed>  $attributes  already validated upstream
     */
    public function create(DriverProfile $profile, array $attributes): Vehicle
    {
        DriverApplicationState::assertEditable($profile->status);

        $limit = (int) config('rafeeq.driver.maximum_vehicles_per_driver');

        if ($profile->vehicles()->count() >= $limit) {
            throw DomainException::of(ErrorCode::VehicleLimitReached);
        }

        $this->assertPlateIsFree($attributes['plateNumber']);

        return DB::transaction(function () use ($profile, $attributes): Vehicle {
            $vehicle = new Vehicle;

            $vehicle->fill($this->columns($attributes));
            $vehicle->driver_profile_id = $profile->user_id;
            $vehicle->save();

            // The first vehicle becomes active on its own: a driver with one
            // car and no active vehicle could not publish anything, and would
            // have no idea why.
            if ($profile->vehicles()->where('is_active', true)->doesntExist()) {
                $this->activate($profile, $vehicle);
            }

            return $vehicle->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(DriverProfile $profile, Vehicle $vehicle, array $attributes): Vehicle
    {
        DriverApplicationState::assertEditable($profile->status);

        if (array_key_exists('plateNumber', $attributes)) {
            $this->assertPlateIsFree($attributes['plateNumber'], exceptVehicleId: $vehicle->id);
        }

        $vehicle->fill($this->columns($attributes));
        $vehicle->save();

        return $vehicle;
    }

    /**
     * Switching the active vehicle. Done in one transaction so the unique
     * index never sees two active rows and the driver is never left with none.
     */
    public function activate(DriverProfile $profile, Vehicle $vehicle): Vehicle
    {
        return DB::transaction(function () use ($profile, $vehicle): Vehicle {
            $profile->vehicles()
                ->where('is_active', true)
                ->whereKeyNot($vehicle->id)
                ->get()
                ->each(fn (Vehicle $other) => $other->forceFill(['is_active' => false])->save());

            $vehicle->forceFill(['is_active' => true])->save();

            return $vehicle;
        });
    }

    private function assertPlateIsFree(string $plateNumber, ?string $exceptVehicleId = null): void
    {
        // Checked here as well as by the unique index: the index gives a
        // database error, and this gives the applicant an answer they can act
        // on. The index remains the thing that makes it true under a race.
        if (DuplicateDetector::plateTaken($plateNumber, $exceptVehicleId)) {
            throw DomainException::of(ErrorCode::DriverDuplicateDetected);
        }
    }

    /**
     * `verification_status` and `is_active` are absent on purpose: a vehicle
     * must never be able to declare itself approved, and activation is its own
     * operation with its own atomicity.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function columns(array $attributes): array
    {
        $map = [
            'make' => 'make',
            'model' => 'model',
            'year' => 'year',
            'colour' => 'colour',
            'plateNumber' => 'plate_number',
            'seats' => 'seats',
            'transmission' => 'transmission',
            'fuelType' => 'fuel_type',
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
