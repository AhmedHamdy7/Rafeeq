<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Commute\Support\CommuteGuards;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Enums\VerificationLogAction;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VerificationLog;
use App\Domains\Driver\Support\VehicleSettings;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * The admin's car review (F2-6, F2-7; PROF-26, PROF-28, ADM-01): how many seats this car may sell,
 * whether it carries the Comfort badge, and whether its air conditioning works.
 *
 * 🔒 The only writer of `approved_seats`, `tier` and `has_ac`. They are not fillable and no driver
 * endpoint reaches them, because together they ARE the price: the tier picks the km rate and the
 * approved seats divide it (PAY-42). A driver who could set either would be setting his own price,
 * which is what D-0016 took away from him.
 *
 * `approved_seats` is the reviewer's count of seats with working belts, never fewer than the
 * minimum (PROF-28: at least 3) — a car that cannot seat three passengers safely is not a carpool
 * car. It is independent of `seats`, which is what the driver typed and only an input to the review.
 *
 * Logged in the append-only `verification_logs` with the old and new values, in the same
 * transaction as the change, like every other verification decision.
 */
final readonly class ReviewVehicleAction
{
    public function execute(Vehicle $vehicle, AdminUser $reviewer, int $approvedSeats, VehicleTier $tier, bool $hasAc): Vehicle
    {
        $min = VehicleSettings::minApprovedSeats();
        $max = (int) config('rafeeq.driver.max_approved_seats');

        if ($approvedSeats < $min || $approvedSeats > $max) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'approvedSeats' => [__('admin.drivers.car.seats_out_of_range', ['min' => $min, 'max' => $max])],
            ]);
        }

        return DB::transaction(function () use ($vehicle, $reviewer, $approvedSeats, $tier, $hasAc): Vehicle {
            $before = [
                'approved_seats' => $vehicle->approved_seats,
                'tier' => $vehicle->tierOrDefault()->value,
                'has_ac' => (bool) $vehicle->has_ac,
            ];

            $vehicle->forceFill([
                'approved_seats' => $approvedSeats,
                'tier' => $tier->value,
                'has_ac' => $hasAc,
            ])->save();

            CommuteGuards::clearAcOnVehicleCommutes($vehicle);

            VerificationLog::create([
                'entity_type' => 'vehicle',
                'entity_id' => $vehicle->id,
                'admin_id' => $reviewer->id,
                'action' => VerificationLogAction::ReviewCar->value,
                'old_value' => $before,
                'new_value' => [
                    'approved_seats' => $approvedSeats,
                    'tier' => $tier->value,
                    'has_ac' => $hasAc,
                ],
                'reason' => null,
            ]);

            return $vehicle;
        });
    }
}
