<?php

namespace Database\Seeders;

use App\Domains\Admin\Actions\SuspendMemberAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Enums\SwitchReason;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Actions\ReviewVehicleAction;
use App\Domains\Driver\Actions\StopExpiredLicencesAction;
use App\Domains\Driver\Actions\SubmitDriverApplicationAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Enums\VehicleRejectedItem;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Geo\Models\Place;
use App\Domains\Identity\Actions\EnrollInitialRoleAction;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Identity\Enums\RegisteredRole;
use App\Domains\Identity\Enums\SuspensionReason;
use App\Domains\Identity\Models\Organization;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Verification\Actions\ReviewVerificationAction;
use App\Domains\Verification\Enums\DocumentKind;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\IdentityDocument;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;

class DriverHomeStatesSeeder extends DemoDataSeeder
{
    /**
     * @var array<string, array{0: string, 1: string}>
     */
    public const array STATES = [
        'F3D-01' => ['+201055557001', 'run'],
        'F3D-01-CAN-START' => ['+201055557016', 'run'],
        'F3D-02' => ['+201055557002', 'run'],
        'F3D-03-PREPARING' => ['+201055557003', 'run'],
        'F3D-03-EN-ROUTE' => ['+201055557017', 'run'],
        'F3D-03-IN-PROGRESS' => ['+201055557018', 'run'],
        'F3D-04' => ['+201055557004', 'run'],
        'F3D-05' => ['+201055557005', 'run'],
        'F3D-06' => ['+201055557006', 'run'],
        'F3D-07' => ['+201055557007', 'run'],
        'F3D-08-REJOINED' => ['+201055557008', 'run'],
        'F3D-08-LEFT' => ['+201055557009', 'run'],
        'NO-PROMISE' => ['+201055557021', 'run'],
        'LATER-WAITLIST' => ['+201055557022', 'run'],
        'SAME-PERSON-TWO-DAYS' => ['+201055557024', 'run'],
        'VERIFIED' => ['+201055557025', 'run'],
        'EXPIRING' => ['+201055557013', 'run'],
        'RENEWAL-LICENCE' => ['+201055557014', 'run'],
        'RENEWAL-REGISTRATION' => ['+201055557026', 'run'],
        'SUSPENDED' => ['+201055557015', 'run'],
        'ACCOUNT-SUSPENDED' => ['+201055557027', 'run'],
        'NO-RUN-ACTIVITY' => ['+201055557028', 'run'],
        'NO-COMMUTES' => ['+201055557023', 'applicant'],
        'NOT-STARTED' => ['+201055557201', 'applicant'],
        'IN-PROGRESS' => ['+201055557202', 'applicant'],
        'COMPLETE-DRAFT' => ['+201055557203', 'applicant'],
        'UNDER-REVIEW' => ['+201055557204', 'applicant'],
        'REJECTED-LICENCE' => ['+201055557205', 'applicant'],
        'REJECTED-REGISTRATION' => ['+201055557206', 'applicant'],
        'REJECTED-DETAILS' => ['+201055557207', 'applicant'],
        'REJECTED-APPLICATION' => ['+201055557208', 'applicant'],
    ];

    private const array MINUTES = [
        'F3D-01' => 130,
        'F3D-01-CAN-START' => 60,
        'F3D-02' => 25,
        'F3D-03-PREPARING' => -8,
        'F3D-03-EN-ROUTE' => -8,
        'F3D-03-IN-PROGRESS' => -8,
        'F3D-04' => -1,
        'F3D-05' => -10,
        'F3D-06' => -30,
    ];

    private const array STARTED = [
        'F3D-03-PREPARING' => [ScheduledTripStatus::Preparing, TripSessionStatus::Preparing],
        'F3D-03-EN-ROUTE' => [ScheduledTripStatus::EnRoute, TripSessionStatus::EnRoute],
        'F3D-03-IN-PROGRESS' => [ScheduledTripStatus::InProgress, TripSessionStatus::InProgress],
    ];

    /**
     * @return list<string>
     */
    public function apply(?string $only = null): array
    {
        $riders = $this->riders();
        $report = [];

        foreach (self::STATES as $state => [$phone, $kind]) {
            if ($only !== null && $only !== $state) {
                continue;
            }

            $kind === 'run' ? $this->applyRunState($state, $phone, $riders) : $this->applyApplicantState($state, $phone);

            $report[] = "{$phone}  {$state}";
        }

        return $report;
    }

    /**
     * @return list<User>
     */
    private function riders(): array
    {
        $riders = [];

        foreach (['+201055557101' => 'Laila', '+201055557102' => 'Salma', '+201055557103' => 'Nour'] as $phone => $first) {
            $riders[] = $this->person($phone, $first, [Capability::Passenger]);
        }

        return $riders;
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    protected function person(string $phone, string $first, array $capabilities): User
    {
        $user = User::query()->where('phone_e164', $phone)->first() ?? User::factory()->woman()->create([
            'phone_e164' => $phone,
            'full_name' => "{$first} Demo",
            'public_first_name' => $first,
            'registered_role' => RegisteredRole::Passenger,
            'account_status' => AccountStatus::Active,
            'profile_status' => ProfileStatus::BasicComplete,
            'initial_role_selected_at' => now(),
        ]);

        EnrollInitialRoleAction::grant($user, $capabilities);

        return $user;
    }

    /**
     * @param  list<User>  $riders
     */
    private function applyRunState(string $state, string $phone, array $riders): void
    {
        $driver = $this->commuteDriver($state, $phone);
        $offer = CommuteOffer::query()->where('driver_profile_id', $driver->id)->with('schedule')->firstOrFail();

        $this->resetDriver($driver, $offer);

        $trip = $this->moveFirstDay($offer, self::MINUTES[$state] ?? 130, $state !== 'NO-PROMISE');
        $later = ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->whereKeyNot($trip->id)
            ->where('departure_at', '>', now())
            ->orderBy('departure_at')
            ->first();

        $this->seatOnce($riders[0], $trip);

        if (in_array($state, ['LATER-WAITLIST'], true)) {
            $this->waitOnce($riders[1], $offer, $later);
        } elseif ($state === 'SAME-PERSON-TWO-DAYS') {
            $this->waitOnce($riders[1], $offer, null);
        } elseif (str_starts_with($state, 'F3D-') || in_array($state, ['RENEWAL-LICENCE', 'SUSPENDED', 'NO-RUN-ACTIVITY'], true)) {
            $this->waitOnce($riders[1], $offer, null);
            $this->waitOnce($riders[2], $offer, $trip);
        }

        $admin = AdminUser::query()->firstOrFail();
        $profile = DriverProfile::query()->whereKey($driver->id)->firstOrFail();

        match ($state) {
            'F3D-03-PREPARING', 'F3D-03-EN-ROUTE', 'F3D-03-IN-PROGRESS' => $this->startRun($trip, ...self::STARTED[$state]),
            'F3D-06' => $this->expireRun($trip),
            'F3D-07' => $this->endSeat($riders[0], $trip, null),
            'F3D-08-REJOINED' => $this->switchSeat($riders[0], $trip, $offer, true),
            'F3D-08-LEFT' => $this->switchSeat($riders[0], $trip, $offer, false),
            'EXPIRING' => $this->setLicenceExpiry($driver, $profile, now()->addDays(10)->startOfDay()),
            'RENEWAL-LICENCE' => $this->lapseLicence($driver, $profile),
            'RENEWAL-REGISTRATION' => $this->lapseRegistration($offer),
            'SUSPENDED' => app(ReviewDriverApplicationAction::class)->suspend($profile, $admin, 'Repeated no-show reports.'),
            'ACCOUNT-SUSPENDED' => app(SuspendMemberAction::class)->suspend($driver->refresh(), $admin, SuspensionReason::SafetyReport, 'Demo account for the whole-account suspension state.'),
            'NO-RUN-ACTIVITY' => $this->pauseWithActivity($riders, $trip, $offer),
            default => null,
        };
    }

    protected function commuteDriver(string $state, string $phone): User
    {
        $driver = User::query()->where('phone_e164', $phone)->first();

        if ($driver !== null && CommuteOffer::query()->where('driver_profile_id', $driver->id)->exists()) {
            return $driver;
        }

        $first = ucfirst(strtolower(str_replace('-', '', $state)));

        $driver = Model::unguarded(fn (): User => $this->seedDriverWithCommute([
            'name' => "{$first} Demo",
            'first' => $first,
            'phone' => $phone,
            'time' => '07:00:00',
            'tier' => VehicleTier::Standard,
            'seats' => 3,
            'plate' => 'DH '.substr($phone, -4),
            'make' => 'Toyota',
            'model' => 'Corolla',
            'colour' => 'White',
            'trips' => 40,
            'onTime' => 95.0,
            'audience' => CommuteAudience::AnyVerified,
        ], Organization::query()->first(), AdminUser::query()->firstOrFail(), Corridor::query()->firstOrFail(), $origin = Place::query()->where('name', 'like', '%الرحاب%')->first() ?? Place::query()->firstOrFail(), Place::query()->where('id', '!=', $origin->id)->firstOrFail()));

        $driver->forceFill(['initial_role_selected_at' => $driver->initial_role_selected_at ?? now()])->save();

        EnrollInitialRoleAction::grant($driver, [Capability::Passenger, Capability::Driver]);

        Artisan::call('commutes:generate-trips');

        return $driver;
    }

    protected function resetDriver(User $driver, CommuteOffer $offer): void
    {
        if ($driver->account_status === AccountStatus::Suspended) {
            app(SuspendMemberAction::class)->reinstate($driver, AdminUser::query()->firstOrFail(), 'Demo state reset.');
            $driver->refresh();
        }

        $expiry = now()->addYears(3)->startOfDay();

        DriverProfile::query()->whereKey($driver->id)->firstOrFail()->forceFill([
            'status' => DriverProfileStatus::Approved->value,
            'rejection_reason' => null,
            'licence_expiry' => $expiry,
            'verified_at' => now(),
        ])->save();

        $vehicle = Vehicle::query()->whereKey($offer->vehicle_id)->firstOrFail();
        $vehicle->forceFill(['verification_status' => VehicleVerificationStatus::Approved->value, 'is_active' => true])->save();

        foreach (VehicleDocument::query()->where('vehicle_id', $vehicle->id)->get() as $document) {
            $document->forceFill(['expires_at' => now()->addYear()])->save();
        }

        if (! VehicleDocument::query()->where('vehicle_id', $vehicle->id)->exists()) {
            VehicleDocument::factory()->create(['vehicle_id' => $vehicle->id, 'type' => VehicleDocumentType::Registration]);
        }

        $licence = UserVerification::query()->where('user_id', $driver->id)->where('type', VerificationType::DrivingLicence->value)->first()
            ?? UserVerification::factory()->create(['user_id' => $driver->id, 'type' => VerificationType::DrivingLicence]);

        $licence->forceFill([
            'status' => VerificationStatus::Approved->value,
            'rejection_reason' => null,
            'expires_at' => $expiry,
            'reviewed_at' => now(),
            'reviewed_by' => AdminUser::query()->firstOrFail()->id,
        ])->save();

        foreach ([DocumentKind::LicenceFront, DocumentKind::LicenceBack] as $kind) {
            IdentityDocument::query()->where('user_verification_id', $licence->id)->where('kind', $kind->value)->exists()
                || IdentityDocument::factory()->create(['user_verification_id' => $licence->id, 'kind' => $kind]);
        }

        EnrollInitialRoleAction::grant($driver, [Capability::Passenger, Capability::Driver]);

        $offer->forceFill([
            'status' => CommuteOfferStatus::Published->value,
            'paused_at' => null,
            'paused_reason' => null,
        ])->save();

        SeatRequest::query()
            ->where('commute_offer_id', $offer->id)
            ->whereIn('status', [SeatRequestStatus::Waitlisted->value, SeatRequestStatus::Pending->value])
            ->update(['status' => SeatRequestStatus::Expired->value, 'waitlist_position' => null]);

        Booking::query()
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()->where('commute_offer_id', $offer->id)->select('id'))
            ->update([
                'status' => BookingStatus::CancelledByDriver->value,
                'cancelled_at' => now()->subDays(3),
                'cancelled_reason' => 'demo_reset',
            ]);

        ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->where('trip_date', '>=', now($offer->schedule->timezone)->toDateString())
            ->update([
                'status' => ScheduledTripStatus::Scheduled->value,
                'seats_taken' => 0,
                'expired_at' => null,
                'delay_driver_reminded_at' => null,
                'delay_passengers_warned_at' => null,
                'delay_violation_at' => null,
            ]);
    }

    protected function moveFirstDay(CommuteOffer $offer, int $minutesFromNow, bool $promise): ScheduledTrip
    {
        $timezone = $offer->schedule->timezone;
        $today = now($timezone)->toDateString();

        $trip = ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->where('trip_date', '>=', $today)
            ->orderBy('trip_date')
            ->firstOrFail();

        TripSession::query()->where('scheduled_trip_id', $trip->id)->delete();

        $departure = now()->addMinutes($minutesFromNow)->startOfMinute();

        $trip->forceFill([
            'trip_date' => $today,
            'departure_at' => $departure,
            'departure_local' => $departure->copy()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'promised_arrival_at' => $promise ? $departure->copy()->addMinutes(70) : null,
        ])->save();

        foreach (ScheduledTrip::query()->where('commute_offer_id', $offer->id)->whereKeyNot($trip->id)->get() as $day) {
            $day->forceFill(['promised_arrival_at' => $promise ? $day->departure_at->copy()->addMinutes(70) : null])->save();
        }

        return $trip->refresh();
    }

    /**
     * @param  list<User>  $riders
     */
    private function pauseWithActivity(array $riders, ScheduledTrip $trip, CommuteOffer $offer): void
    {
        $this->endSeat($riders[0], $trip, null);

        SeatRequest::factory()->create([
            'passenger_user_id' => $riders[0]->id,
            'commute_offer_id' => $offer->id,
            'scheduled_trip_id' => $trip->id,
            'status' => SeatRequestStatus::Pending,
            'commitment' => SeatRequestCommitment::Trial,
        ]);

        $offer->forceFill([
            'status' => CommuteOfferStatus::Paused->value,
            'paused_at' => now(),
            'paused_reason' => CommutePausedReason::ByDriver->value,
        ])->save();
    }

    protected function seatOnce(User $rider, ScheduledTrip $trip): void
    {
        $booking = Booking::query()
            ->where('scheduled_trip_id', $trip->id)
            ->where('passenger_user_id', $rider->id)
            ->first();

        if ($booking === null) {
            $this->seatOn($rider, $trip->load('commuteOffer'));

            return;
        }

        $booking->forceFill([
            'status' => BookingStatus::Confirmed->value,
            'cancelled_at' => null,
            'cancelled_reason' => null,
        ])->save();

        $trip->increment('seats_taken');
    }

    private function waitOnce(User $rider, CommuteOffer $offer, ?ScheduledTrip $trip): void
    {
        SeatRequest::factory()->waitlisted()->create([
            'passenger_user_id' => $rider->id,
            'commute_offer_id' => $offer->id,
            'scheduled_trip_id' => $trip?->id,
            'commitment' => $trip === null ? SeatRequestCommitment::Recurring : SeatRequestCommitment::Trial,
            'requested_days_mask' => $trip === null ? DaysMask::fromBits(127)->value : null,
        ]);
    }

    private function startRun(ScheduledTrip $trip, ScheduledTripStatus $status, TripSessionStatus $session): void
    {
        $row = new TripSession;
        $row->fill(['scheduled_trip_id' => $trip->id, 'started_at' => now()->subMinutes(10)]);
        $row->current_status = $session->value;
        $row->save();

        $trip->forceFill(['status' => $status->value])->save();
    }

    private function expireRun(ScheduledTrip $trip): void
    {
        Booking::query()
            ->where('scheduled_trip_id', $trip->id)
            ->where('status', BookingStatus::Confirmed->value)
            ->update([
                'status' => BookingStatus::ExitedDueToDriverDelay->value,
                'cancelled_at' => now()->subMinutes(10),
                'cancelled_reason' => 'trip_expired',
            ]);

        $trip->forceFill([
            'status' => ScheduledTripStatus::Expired->value,
            'expired_at' => now()->subMinutes(10),
            'delay_violation_at' => now()->subMinutes(20),
            'seats_taken' => 0,
        ])->save();
    }

    private function endSeat(User $rider, ScheduledTrip $trip, ?string $reason): void
    {
        Booking::query()
            ->where('scheduled_trip_id', $trip->id)
            ->where('passenger_user_id', $rider->id)
            ->firstOrFail()
            ->forceFill([
                'status' => BookingStatus::CancelledByPassenger->value,
                'cancelled_at' => now(),
                'cancelled_reason' => $reason,
            ])
            ->save();

        $trip->decrement('seats_taken');
    }

    private function switchSeat(User $rider, ScheduledTrip $trip, CommuteOffer $offer, bool $rejoined): void
    {
        $this->endSeat($rider, $trip, SwitchReason::Voluntary->value);

        if ($rejoined) {
            $this->waitOnce($rider, $offer, $trip);
        }
    }

    private function setLicenceExpiry(User $driver, DriverProfile $profile, mixed $date): void
    {
        $profile->forceFill(['licence_expiry' => $date])->save();

        UserVerification::query()
            ->where('user_id', $driver->id)
            ->where('type', VerificationType::DrivingLicence->value)
            ->update(['expires_at' => $date]);
    }

    private function lapseLicence(User $driver, DriverProfile $profile): void
    {
        $this->setLicenceExpiry($driver, $profile, now()->subDay()->startOfDay());

        app(StopExpiredLicencesAction::class)->stop($driver->id);
    }

    private function lapseRegistration(CommuteOffer $offer): void
    {
        VehicleDocument::query()
            ->where('vehicle_id', $offer->vehicle_id)
            ->update(['expires_at' => now()->subDay()]);
    }

    private function applyApplicantState(string $state, string $phone): void
    {
        $first = ucfirst(strtolower(str_replace('-', '', $state)));
        $user = $this->person($phone, $first, [Capability::Passenger, Capability::Driver]);

        if ($state === 'NOT-STARTED') {
            return;
        }

        $admin = AdminUser::query()->firstOrFail();

        foreach ([VerificationType::Phone, VerificationType::GovernmentId, VerificationType::Selfie] as $type) {
            UserVerification::query()->where('user_id', $user->id)->where('type', $type->value)->exists()
                || UserVerification::factory()->approved()->create(['user_id' => $user->id, 'type' => $type, 'reviewed_by' => $admin->id]);
        }

        $profile = DriverProfile::query()->whereKey($user->id)->first()
            ?? DriverProfile::factory()->create(['user_id' => $user->id, 'status' => DriverProfileStatus::Draft]);

        $profile->forceFill([
            'status' => DriverProfileStatus::Draft->value,
            'rejection_reason' => null,
            'verified_at' => null,
            'licence_expiry' => now()->addYears(3)->startOfDay(),
        ])->save();

        if ($state === 'IN-PROGRESS') {
            return;
        }

        $licence = $this->resetLicenceEvidence($user);
        $vehicle = $this->resetVehicleEvidence($profile, $phone);

        if ($state === 'COMPLETE-DRAFT') {
            return;
        }

        $profile = app(SubmitDriverApplicationAction::class)->submit($profile->refresh());
        $review = app(ReviewDriverApplicationAction::class);

        match ($state) {
            'REJECTED-LICENCE' => app(ReviewVerificationAction::class)->requestMoreInfo($licence->refresh(), $admin, 'The licence photo is too blurry to read. Please upload it again.'),
            'REJECTED-REGISTRATION' => $review->rejectVehicle($profile, $vehicle, $admin, VehicleRejectedItem::Registration, 'The registration photo is too blurry to read.'),
            'REJECTED-DETAILS' => $review->rejectVehicle($profile, $vehicle, $admin, VehicleRejectedItem::Details, 'The plate number does not match the registration.'),
            'REJECTED-APPLICATION' => $review->reject($profile, $admin, 'The documents do not belong to the same person.'),
            'NO-COMMUTES' => $this->approveApplicant($profile, $vehicle, $admin),
            default => null,
        };
    }

    private function approveApplicant(DriverProfile $profile, Vehicle $vehicle, AdminUser $admin): void
    {
        app(ReviewVehicleAction::class)->execute($vehicle->refresh(), $admin, 3, VehicleTier::Standard, true);
        app(ReviewDriverApplicationAction::class)->approve($profile->refresh(), $admin);
    }

    private function resetLicenceEvidence(User $user): UserVerification
    {
        $licence = UserVerification::query()->where('user_id', $user->id)->where('type', VerificationType::DrivingLicence->value)->first()
            ?? UserVerification::factory()->create(['user_id' => $user->id, 'type' => VerificationType::DrivingLicence]);

        $licence->forceFill([
            'status' => VerificationStatus::NotStarted->value,
            'rejection_reason' => null,
            'expires_at' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'attempt_count' => 0,
        ])->save();

        foreach ([DocumentKind::LicenceFront, DocumentKind::LicenceBack] as $kind) {
            IdentityDocument::query()->where('user_verification_id', $licence->id)->where('kind', $kind->value)->exists()
                || IdentityDocument::factory()->create(['user_verification_id' => $licence->id, 'kind' => $kind]);
        }

        return $licence;
    }

    private function resetVehicleEvidence(DriverProfile $profile, string $phone): Vehicle
    {
        $plate = 'DH '.substr($phone, -4);

        $vehicle = Vehicle::query()->where('driver_profile_id', $profile->user_id)->first()
            ?? Vehicle::factory()->create([
                'driver_profile_id' => $profile->user_id,
                'plate_number' => $plate,
                'plate_normalized' => Vehicle::normalizePlate($plate),
            ]);

        $vehicle->forceFill([
            'verification_status' => VehicleVerificationStatus::Pending->value,
            'rejected_item' => null,
            'rejection_reason' => null,
            'is_active' => true,
            'approved_seats' => null,
        ])->save();

        if (! VehicleDocument::query()->where('vehicle_id', $vehicle->id)->exists()) {
            VehicleDocument::factory()->create(['vehicle_id' => $vehicle->id, 'type' => VehicleDocumentType::Registration]);
        }

        return $vehicle;
    }
}
