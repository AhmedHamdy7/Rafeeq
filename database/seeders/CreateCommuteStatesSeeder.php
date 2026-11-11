<?php

namespace Database\Seeders;

use App\Domains\Commute\Actions\CreateCommuteOfferAction;
use App\Domains\Commute\Actions\SaveCommuteRouteAction;
use App\Domains\Commute\Actions\SaveCommuteScheduleAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\SettlementWeek;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;

final class CreateCommuteStatesSeeder extends DriverHomeStatesSeeder
{
    public const array CREATE_STATES = [
        'CC-READY' => '+201055557401',
        'CC-CAR-NOT-REVIEWED' => '+201055557402',
        'CC-CAR-REJECTED' => '+201055557403',
        'CC-CAR-PENDING' => '+201055557404',
        'CC-NO-CAR' => '+201055557405',
        'CC-UNVERIFIED' => '+201055557406',
        'CC-OVERDUE' => '+201055557407',
        'CC-SUSPENDED' => '+201055557408',
        'CC-LICENCE-EXPIRED' => '+201055557409',
        'CC-CAR-NO-AC' => '+201055557410',
        'CC-MAN-DRIVER' => '+201055557411',
        'CC-WINDOW-PASSED' => '+201055557412',
        'CC-PENDING-REVIEW' => '+201055557413',
        'CC-DRIVER-REJECTED' => '+201055557414',
    ];

    private const array ROUTE = [
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913, 'address' => 'Rehab Gate 2'],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716, 'address' => 'Smart Village B6'],
    ];

    private const array SCHEDULE = ['daysMask' => 62, 'promisedArrivalTime' => '09:00:00'];

    private const array DRAFT_SCHEDULE = ['daysMask' => 62, 'promisedArrivalTime' => '18:00:00'];

    public function applyCreateStates(?string $only = null): array
    {
        $report = [];

        foreach (self::CREATE_STATES as $state => $phone) {
            if ($only !== null && $only !== $state) {
                continue;
            }

            $this->applyCreateState($state, $phone);

            $report[] = "{$phone}  {$state}";
        }

        return $report;
    }

    private function applyCreateState(string $state, string $phone): void
    {
        if ($state === 'CC-UNVERIFIED' || $state === 'CC-NO-CAR') {
            $this->withoutCar($state, $phone);

            return;
        }

        $driver = $this->commuteDriver($state, $phone);
        $published = CommuteOffer::query()
            ->where('driver_profile_id', $driver->id)
            ->whereNotNull('published_at')
            ->with('locations', 'schedule')
            ->orderBy('id')
            ->firstOrFail();

        $this->resetDriver($driver, $published);
        $this->clearDrafts($driver->id);
        $this->clearArchived($driver->id, $published->id);

        if (! $published->weekdaySchedules()->exists()) {
            CommuteWeekdaySchedule::fillFromSchedule($published->schedule);
        }

        $published->weekdaySchedules()->update(['promised_arrival_time' => self::SCHEDULE['promisedArrivalTime'], 'departure_is_computed' => true]);
        $this->clearDebt($driver->id);

        $profile = DriverProfile::query()->whereKey($driver->id)->firstOrFail();
        $vehicle = Vehicle::query()->whereKey($published->vehicle_id)->firstOrFail();
        $vehicle->forceFill(['approved_seats' => 3, 'rejection_reason' => null, 'rejected_item' => null])->save();

        $draft = app(CreateCommuteOfferAction::class)->execute($profile, ['vehicleId' => $vehicle->id]);
        app(SaveCommuteRouteAction::class)->execute($draft, self::ROUTE);
        app(SaveCommuteScheduleAction::class)->execute($draft->refresh(), self::DRAFT_SCHEDULE);

        match ($state) {
            'CC-CAR-NOT-REVIEWED' => $vehicle->forceFill(['approved_seats' => null])->save(),
            'CC-CAR-REJECTED' => $vehicle->forceFill([
                'verification_status' => VehicleVerificationStatus::Rejected->value,
                'rejection_reason' => 'The registration photo is unreadable.',
            ])->save(),
            'CC-CAR-PENDING' => $vehicle->forceFill([
                'verification_status' => VehicleVerificationStatus::Pending->value,
                'approved_seats' => null,
            ])->save(),
            'CC-CAR-NO-AC' => $vehicle->forceFill(['has_ac' => false])->save(),
            'CC-MAN-DRIVER' => $driver->forceFill(['gender' => Gender::Man->value])->save(),
            'CC-WINDOW-PASSED' => $published->schedule->forceFill([
                'start_date' => now()->subMonths(2)->toDateString(),
                'end_date' => now()->subDay()->toDateString(),
            ])->save(),
            'CC-PENDING-REVIEW' => $profile->forceFill(['status' => DriverProfileStatus::PendingReview->value])->save(),
            'CC-DRIVER-REJECTED' => $profile->forceFill([
                'status' => DriverProfileStatus::Rejected->value,
                'rejection_reason' => 'The licence photo is unreadable.',
            ])->save(),
            'CC-OVERDUE' => $this->oweLastWeek($driver),
            'CC-SUSPENDED' => $profile->forceFill([
                'status' => DriverProfileStatus::Suspended->value,
                'rejection_reason' => 'Two conduct reports this week.',
            ])->save(),
            'CC-LICENCE-EXPIRED' => $profile->forceFill([
                'status' => DriverProfileStatus::ExpiredDocuments->value,
                'licence_expiry' => now()->subDay()->startOfDay(),
            ])->save(),
            default => null,
        };
    }

    private function withoutCar(string $state, string $phone): void
    {
        $first = ucfirst(strtolower(str_replace('-', '', $state)));
        $user = $this->person($phone, $first, [Capability::Passenger, Capability::Driver]);

        $this->clearDrafts($user->id);

        UserVerification::query()
            ->where('user_id', $user->id)
            ->where('type', VerificationType::GovernmentId->value)
            ->delete();

        if ($state === 'CC-UNVERIFIED') {
            DriverProfile::query()->whereKey($user->id)->delete();

            return;
        }

        UserVerification::factory()->approved()->create([
            'user_id' => $user->id,
            'type' => VerificationType::GovernmentId,
        ]);

        if (! DriverProfile::query()->whereKey($user->id)->exists()) {
            DriverProfile::factory()->create(['user_id' => $user->id, 'status' => DriverProfileStatus::Approved]);
        }
    }

    private function clearDrafts(string $driverId): void
    {
        CommuteOffer::query()
            ->where('driver_profile_id', $driverId)
            ->whereNull('published_at')
            ->whereIn('status', [CommuteOfferStatus::Draft->value, CommuteOfferStatus::Archived->value])
            ->delete();
    }

    private function clearArchived(string $driverId, string $keepId): void
    {
        CommuteOffer::query()
            ->where('driver_profile_id', $driverId)
            ->whereKeyNot($keepId)
            ->where('status', CommuteOfferStatus::Archived->value)
            ->whereDoesntHave('scheduledTrips.bookings')
            ->get()
            ->each(function (CommuteOffer $offer): void {
                $offer->scheduledTrips()->delete();
                $offer->delete();
            });
    }

    private function clearDebt(string $driverId): void
    {
        DebtEntry::query()->where('user_id', $driverId)->delete();
        DebtAccount::query()->where('user_id', $driverId)->delete();
    }

    private function oweLastWeek(User $driver): void
    {
        DebtLedger::record(
            $driver->id,
            DebtSide::Driver,
            DebtEntryType::Commission,
            18_000,
            'seat_commission',
            weekOf: SettlementWeek::shift(SettlementWeek::current(), -1),
        );
    }
}
