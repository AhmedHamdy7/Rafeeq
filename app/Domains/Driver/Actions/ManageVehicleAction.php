<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Actions\CreateCommuteOfferAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Support\DuplicateDetector;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
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
        // Wider than `assertEditable` on purpose: an APPROVED driver must be able to add a
        // replacement car, which is the whole of vehicle replacement. See the state machine.
        DriverApplicationState::assertMayAddVehicle($profile->status);

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

            /*
             * The first vehicle becomes active on its own: a driver with one car and no active
             * vehicle could not publish anything, and would have no idea why.
             *
             * 🔴 Deliberately NOT through `activate()`. That method now refuses an unapproved
             * vehicle — which is right for a replacement and wrong here, because a brand-new
             * vehicle is unapproved by definition and this is the moment it is created. It is also
             * a different operation: there is nothing to deactivate and no commute to move.
             *
             * Being active is not permission to drive. `usableVehicle()` requires approved AND
             * active at publish and at trip start, so the flag on its own carries her nowhere.
             */
            if ($profile->vehicles()->where('is_active', true)->doesntExist()) {
                $vehicle->forceFill(['is_active' => true])->save();
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
    /**
     * Making one of the driver's vehicles the active one — which is how a replacement car enters
     * service (Feature 02 v3: "vehicle replacement = new re-verification request").
     *
     * 🔒 **The approval check is new and it is the point of this method now.** Activation used to
     * flip `is_active` for any vehicle the driver owned, approved or not. That did not let her
     * carry anybody — `usableVehicle()` is re-run at publish and at trip start, so a commute bound
     * to a now-inactive car refuses — but it let her take her own working commutes offline with one
     * tap and no warning, and every passenger booked on them would have found out when the run
     * failed to start. v3 says "prevent new driving on unapproved replacement"; refusing here is
     * the cheapest place to honour it.
     *
     * 🔴 Confirmed future bookings are **kept, not cancelled** (product decision, 2026-10-16). So
     * her commutes move with her to the new car and every affected passenger is told — including
     * which car, because the plate she was shown has changed and that is what she matches at the
     * kerb.
     */
    public function activate(DriverProfile $profile, Vehicle $vehicle): Vehicle
    {
        if ($vehicle->verification_status !== VehicleVerificationStatus::Approved) {
            throw DomainException::of(ErrorCode::VehicleNotAllowed, fields: [
                // `?->` because a model that has been saved but not reloaded holds null here: the
                // column's `pending` default lives in the database, not in the instance.
                'verificationStatus' => [$vehicle->verification_status?->value ?? 'pending'],
            ]);
        }

        return DB::transaction(function () use ($profile, $vehicle): Vehicle {
            $outgoing = $profile->vehicles()
                ->where('is_active', true)
                ->whereKeyNot($vehicle->id)
                ->get();

            /*
             * Checked BEFORE anything is written, for every commute that would move.
             *
             * A replacement is often a smaller car, and a commute selling three seats cannot move
             * into a four-seater. Refusing the whole activation is the only honest answer: the
             * alternatives are to cancel somebody's booked seat — which the product decision rules
             * out — or to leave her commute bound to a car that is no longer active, which breaks
             * it silently. Better that the driver learns now, while she can still choose.
             */
            $moving = $this->commutesOn($profile, $outgoing);

            foreach ($moving as $offer) {
                CreateCommuteOfferAction::assertSeatsFitVehicle($vehicle, (int) $offer->seats_total);
            }

            $outgoing->each(fn (Vehicle $other) => $other->forceFill(['is_active' => false])->save());

            $vehicle->forceFill(['is_active' => true])->save();

            foreach ($moving as $offer) {
                $offer->forceFill(['vehicle_id' => $vehicle->getKey()])->save();

                $this->tellPassengers($offer, $vehicle);
            }

            return $vehicle;
        });
    }

    /**
     * The driver's commutes currently bound to any of the outgoing vehicles.
     *
     * Archived commutes are left alone: moving a car into a commute nobody can book is work that
     * changes nothing, and it would notify people about a journey that is over.
     *
     * @param  \Illuminate\Support\Collection<int, Vehicle>  $outgoing
     * @return \Illuminate\Support\Collection<int, CommuteOffer>
     */
    private function commutesOn(DriverProfile $profile, $outgoing)
    {
        if ($outgoing->isEmpty()) {
            return collect();
        }

        return CommuteOffer::query()
            ->where('driver_profile_id', $profile->user_id)
            ->whereIn('vehicle_id', $outgoing->modelKeys())
            ->whereNot('status', CommuteOfferStatus::Archived->value)
            ->get();
    }

    /**
     * Tell everybody holding a live seat on a future day that a different car is coming.
     *
     * 🔒 Only her own booking's passengers, and nothing about each other: the loop notifies one
     * person at a time with the vehicle and nothing else. A passenger list is not something a
     * notification should carry.
     */
    private function tellPassengers(CommuteOffer $offer, Vehicle $vehicle): void
    {
        $passengers = Booking::query()
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->where('commute_offer_id', $offer->getKey())
                ->whereDate('departure_at', '>=', now()->toDateString())
                ->select('id'))
            // Confirmed only. A trial is a flag on a confirmed booking, not a status of its own,
            // and a pending request has not been promised a car yet.
            ->where('status', BookingStatus::Confirmed->value)
            ->with('passenger')
            ->get();

        $driverName = $offer->driverProfile?->user?->public_first_name ?? '';

        foreach ($passengers as $booking) {
            if ($booking->passenger === null) {
                continue;
            }

            Notifier::send($booking->passenger, NotificationType::BookingVehicleChanged, params: [
                'name' => $driverName,
                'vehicle' => trim($vehicle->make.' '.$vehicle->model),
                'plate' => $vehicle->plate_number,
            ], data: [
                'bookingId' => $booking->getKey(),
                'vehicleId' => $vehicle->getKey(),
            ]);
        }
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
