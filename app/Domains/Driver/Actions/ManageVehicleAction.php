<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Booking\Actions\DisplaceConfirmedBookingsAction;
use App\Domains\Booking\Enums\DisplacementReason;
use App\Domains\Commute\Actions\CreateCommuteOfferAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Support\DuplicateDetector;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Collection;
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
    public function __construct(private DisplaceConfirmedBookingsAction $displace) {}

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
     * 🔴 **Confirmed future bookings are CANCELLED, and this reverses what this method used to do.**
     *
     * Until Feature 03 v5 (2026-10-18) they were kept: the commutes moved to the new car and every
     * passenger was told which plate to look for (product decision, 2026-10-16, implemented and
     * tested). v5 overrides it in terms — *"Replace Vehicle requires new verification and cancels
     * all future confirmed bookings across that driver's commutes; displaced riders enter preserved
     * waitlists. Keep account, score, history, templates. No automatic restoration."*
     *
     * The argument v5 wins on is the one the old behaviour could not answer. A replacement car
     * starts a **fresh verification**, and `usableVehicle()` refuses to start a run in a car no
     * reviewer has approved. So a booking "kept" on the new car was never a seat she could actually
     * ride in — it was a confirmed booking the platform already knew it could not honour, and she
     * would have found out at the kerb. Cancelling says so now, while she can still arrange
     * something else, and puts her back on the list so she gets first look when the new car clears.
     *
     * 🔴 Note that this fires for EVERY activation, including a driver returning to a previous car
     * she still has approved — v5 allows that return "without re-verification" but still says
     * canceled bookings do not come back. The seats were already cancelled when she left that car,
     * so there is nothing to cancel on the way back; the sweep simply finds nothing.
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
             * A replacement is often a smaller car, and a commute advertising four seats cannot run
             * in a three-seater: the seat count is published, people search on it, and the weekday
             * rows price against it. Refusing the whole activation is still the honest answer even
             * now that the booked seats are cancelled anyway — the alternative is a live commute
             * selling more seats than the car has, or one bound to a vehicle that is no longer
             * active, which breaks it silently.
             */
            $moving = $this->commutesOn($profile, $outgoing);

            foreach ($moving as $offer) {
                CreateCommuteOfferAction::assertCommuteFitsVehicle($offer, $vehicle);
            }

            /*
             * 🔴 Every future confirmed seat she has sold, across every commute, goes — and the
             * passengers go back on the waiting lists (v5, "Vehicle management").
             *
             * Guarded on `$outgoing` because this method also serves an activation that replaces
             * NOTHING: re-activating the car that is already active, which would otherwise cancel
             * her whole book of business for a no-op. A replacement is precisely the case where
             * another vehicle is being stood down.
             *
             * Before the flags are flipped, so a failure here leaves her on the car she was
             * driving rather than mid-swap.
             */
            if ($outgoing->isNotEmpty()) {
                $this->displace->forDriver($profile, DisplacementReason::VehicleReplaced);
            }

            $outgoing->each(fn (Vehicle $other) => $other->forceFill(['is_active' => false])->save());

            $vehicle->forceFill(['is_active' => true])->save();

            /*
             * The commutes themselves still move, and this is not in tension with cancelling the
             * bookings. v5 keeps "account, score, history, templates" — the commute IS the
             * template, and leaving it pointing at a stood-down car would mean she has to rebuild
             * every one of them by hand before she can resume. What she has to rebuild is her
             * passenger list, which is what the preserved waiting list is for.
             */
            foreach ($moving as $offer) {
                $offer->forceFill(['vehicle_id' => $vehicle->getKey()])->save();
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
     * @param  Collection<int, Vehicle>  $outgoing
     * @return Collection<int, CommuteOffer>
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
