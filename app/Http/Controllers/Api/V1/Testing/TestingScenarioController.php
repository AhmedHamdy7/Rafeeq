<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Admin\Actions\ManageDebtAction;
use App\Domains\Admin\Actions\SuspendMemberAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Actions\VehicleSuspensionAction;
use App\Domains\Driver\Enums\VehicleSuspensionReason;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Identity\Enums\SuspensionReason;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Actions\SuspendForDebtAction;
use App\Domains\Payment\Actions\SweepDebtsAction;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Support\DebtStanding;
use App\Domains\Payment\Support\PassengerFees;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Domains\Trip\Actions\AdvanceTripAction;
use App\Domains\Trip\Actions\WaitTimerAction;
use App\Domains\Trip\Enums\WarningFeeStatus;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\MemberWarning;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Testing\AgeBookingRequest;
use App\Http\Requests\Testing\AgeDebtRequest;
use App\Http\Requests\Testing\DebtDeadlineRequest;
use App\Http\Requests\Testing\DocumentExpiryRequest;
use App\Http\Requests\Testing\ExpireWaitRequest;
use App\Http\Requests\Testing\RejectTestPaymentRequest;
use App\Http\Requests\Testing\SeedHistoryRequest;
use App\Http\Requests\Testing\SuspensionScopeRequest;
use App\Http\Resources\DebtPaymentResource;
use App\Http\Resources\DebtSideResource;
use App\Http\Resources\WaitTimerResource;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class TestingScenarioController extends Controller
{
    public const array JOBS = [
        'generate-trips' => 'commutes:generate-trips',
        'roll-memberships' => 'memberships:roll-forward',
        'match-demands' => 'demands:match-existing',
        'expire-demands' => 'demands:expire',
        'expire-requests' => 'requests:expire',
        'reveal-ratings' => 'ratings:reveal-due',
        'rating-reminders' => 'ratings:send-reminders',
        'trip-reminders' => 'trips:send-reminders',
        'auto-finish-trips' => 'trips:auto-finish',
        'sweep-delays' => 'trips:sweep-delays',
        'settle-cash' => 'payments:settle-cash',
        'sweep-debts' => 'payments:sweep-debts',
        'warn-licences' => 'licences:warn-expiring',
        'stop-licences' => 'licences:stop-expired',
        'warn-registrations' => 'registrations:warn-expiring',
        'stop-registrations' => 'registrations:stop-expired',
    ];

    #[ApiErrors(ErrorCode::DebtPaymentNotPending, ErrorCode::ServiceUnavailable, ErrorCode::NotFound)]
    public function confirmPayment(Request $request, string $payment, ManageDebtAction $action): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        return ApiResponse::success(new DebtPaymentResource(
            $action->confirmPayment($this->paymentOf($user, $payment), $this->reviewer())
        ));
    }

    #[ApiErrors(ErrorCode::DebtPaymentNotPending, ErrorCode::ServiceUnavailable, ErrorCode::NotFound)]
    public function rejectPayment(RejectTestPaymentRequest $request, string $payment, ManageDebtAction $action): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        return ApiResponse::success(new DebtPaymentResource($action->rejectPayment(
            $this->paymentOf($user, $payment),
            $this->reviewer(),
            (string) ($request->validated('note') ?? 'Rejected by the test run.'),
        )));
    }

    #[ApiErrors(ErrorCode::TripNotStarted, ErrorCode::NotFound)]
    public function expireWait(ExpireWaitRequest $request, string $trip, WaitTimerAction $timers): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $session = AdvanceTripAction::sessionFor($this->drivenTrip($user, $trip));

        $timer = $timers->running($session, (string) $request->validated('bookingId'))
            ?? throw DomainException::of(ErrorCode::NotFound);

        $graceStartsAt = CarbonImmutable::now()->subSeconds($timer->grace_seconds + (int) $timer->extended_seconds + 1);

        $timer->forceFill([
            'grace_starts_at' => $graceStartsAt,
            'started_at' => $timer->started_at->lessThan($graceStartsAt) ? $timer->started_at : $graceStartsAt,
        ])->save();

        return ApiResponse::success(new WaitTimerResource(
            $timer->refresh()->load('booking.passenger.stats', 'booking.passenger.verifications')
        ));
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function debtDeadline(DebtDeadlineRequest $request, SweepDebtsAction $sweep, SuspendForDebtAction $suspensions): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());
        $side = DebtSide::from((string) ($request->validated('side') ?? DebtSide::Driver->value));

        $this->shiftDebt($user, $side, 7, weeks: true);

        $sweep->execute();
        $side === DebtSide::Driver ? $suspensions->driverIfDue($user->id) : $suspensions->passengerIfDue($user->id);

        return ApiResponse::success(new DebtSideResource(DebtStanding::of($user, $side)));
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function ageDebt(AgeDebtRequest $request, SweepDebtsAction $sweep, SuspendForDebtAction $suspensions): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());
        $side = DebtSide::from((string) ($request->validated('side') ?? DebtSide::Passenger->value));

        $this->shiftDebt($user, $side, (int) $request->validated('days'), weeks: false);

        $sweep->execute();
        $side === DebtSide::Driver ? $suspensions->driverIfDue($user->id) : $suspensions->passengerIfDue($user->id);

        return ApiResponse::success(new DebtSideResource(DebtStanding::of($user, $side)));
    }

    #[ApiErrors(ErrorCode::MemberNotActive, ErrorCode::ServiceUnavailable, ErrorCode::NotFound)]
    public function suspend(SuspensionScopeRequest $request, SuspendMemberAction $members, VehicleSuspensionAction $vehicles): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());
        $reason = (string) ($request->validated('reason') ?? 'Suspended by the test run.');

        if ($request->validated('scope') === 'account') {
            $members->suspend($user, $this->reviewer(), SuspensionReason::PolicyBreach, $reason);
        } else {
            $vehicles->suspend($this->vehicleOf($user, $request->validated('vehicleId')), VehicleSuspensionReason::Report, $this->reviewer(), $reason);
        }

        return ApiResponse::success($this->standing($user, $request->validated('vehicleId')));
    }

    #[ApiErrors(ErrorCode::MemberNotSuspended, ErrorCode::ServiceUnavailable, ErrorCode::NotFound)]
    public function reinstate(SuspensionScopeRequest $request, SuspendMemberAction $members, VehicleSuspensionAction $vehicles): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        if ($request->validated('scope') === 'account') {
            $members->reinstate($user, $this->reviewer(), (string) ($request->validated('reason') ?? 'Reinstated by the test run.'));
        } else {
            $vehicles->reinstate($this->vehicleOf($user, $request->validated('vehicleId')), $this->reviewer());
        }

        return ApiResponse::success($this->standing($user, $request->validated('vehicleId')));
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function documentExpiry(DocumentExpiryRequest $request, string $key): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());
        $expiresAt = CarbonImmutable::parse((string) $request->validated('expiresAt'))->toDateString();

        if ($key === 'licence') {
            $profile = DriverProfile::query()->whereKey($user->id)->first()
                ?? throw DomainException::of(ErrorCode::NotFound);

            $profile->forceFill(['licence_expiry' => $expiresAt])->save();

            UserVerification::query()
                ->where('user_id', $user->id)
                ->where('type', VerificationType::DrivingLicence->value)
                ->update(['expires_at' => $expiresAt]);

            Artisan::call('licences:warn-expiring');
            Artisan::call('licences:stop-expired');
        } elseif ($key === 'vehicle_registration') {
            $vehicle = $this->vehicleOf($user, $request->validated('vehicleId'));

            $updated = VehicleDocument::query()
                ->where('vehicle_id', $vehicle->id)
                ->whereIn('type', VehicleDocument::REGISTRATION_TYPES)
                ->update(['expires_at' => $expiresAt, 'expiry_warned_for' => null]);

            if ($updated === 0) {
                throw DomainException::of(ErrorCode::NotFound);
            }

            Artisan::call('registrations:warn-expiring');
            Artisan::call('registrations:stop-expired');
        } else {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return ApiResponse::success(['key' => $key, 'expiresAt' => $expiresAt]);
    }

    #[ApiErrors(ErrorCode::TripAlreadyStarted, ErrorCode::NotFound)]
    public function ageBooking(AgeBookingRequest $request, string $booking): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());
        $days = (int) $request->validated('days');

        $found = Booking::query()
            ->whereKey($booking)
            ->where(fn ($either) => $either
                ->where('passenger_user_id', $user->id)
                ->orWhereIn('scheduled_trip_id', ScheduledTrip::query()
                    ->whereIn('commute_offer_id', CommuteOffer::query()->where('driver_profile_id', $user->id)->select('id'))
                    ->select('id')))
            ->with('scheduledTrip.tripSession')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $trip = $found->scheduledTrip ?? throw DomainException::of(ErrorCode::NotFound);

        if ($trip->tripSession?->current_status->isUnderway() === true) {
            throw DomainException::of(ErrorCode::TripAlreadyStarted);
        }

        DB::transaction(function () use ($trip, $days): void {
            $this->shift('scheduled_trips', ['departure_at', 'promised_arrival_at', 'departure_local'], fn ($q) => $q->where('id', $trip->id), $days);
            $this->shift('trip_sessions', ['started_at', 'departed_at', 'completed_at'], fn ($q) => $q->where('scheduled_trip_id', $trip->id), $days);
            $this->shift('bookings', ['cancelled_at'], fn ($q) => $q->where('scheduled_trip_id', $trip->id), $days);
            $this->shift('attendance', ['checked_in_at', 'checked_out_at', 'confirmed_at', 'updated_at'], fn ($q) => $q->whereIn('booking_id', Booking::query()->where('scheduled_trip_id', $trip->id)->select('id')), $days);
        });

        Artisan::call('ratings:reveal-due');

        $trip->refresh();

        return ApiResponse::success([
            'bookingId' => $found->id,
            'tripId' => $trip->id,
            'departureAt' => $trip->departure_at->toIso8601String(),
        ]);
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function runJob(Request $request, string $name): JsonResponse
    {
        TestAccounts::assertAllowed($request->user());

        $command = self::JOBS[$name] ?? throw DomainException::of(ErrorCode::NotFound);

        $exitCode = Artisan::call($command);

        return ApiResponse::success([
            'job' => $name,
            'command' => $command,
            'exitCode' => $exitCode,
            'output' => trim(Artisan::output()),
        ]);
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function seedHistory(SeedHistoryRequest $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());
        $kind = WarningKind::from((string) $request->validated('kind'));
        $count = (int) $request->validated('count');

        $existing = MemberWarning::query()->standing($user->id, $kind)->count();

        for ($i = 0; $i < $count; $i++) {
            $warning = new MemberWarning;
            $warning->forceFill([
                'user_id' => $user->id,
                'kind' => $kind,
                'booking_id' => null,
                'ordinal' => $existing + $i + 1,
                'fee_eligible' => false,
                'fee_status' => WarningFeeStatus::None,
                'occurred_on' => CarbonImmutable::today()->subDays($count - $i)->toDateString(),
                'delivered_at' => now(),
            ])->save();
        }

        return ApiResponse::success([
            'kind' => $kind->value,
            'standing' => MemberWarning::query()->standing($user->id, $kind)->count(),
            'nextRung' => PassengerFees::nextRung($user->id, $kind, CarbonImmutable::today()),
        ]);
    }

    private function reviewer(): AdminUser
    {
        return AdminUser::query()->orderBy('created_at')->first()
            ?? throw DomainException::of(ErrorCode::ServiceUnavailable);
    }

    private function paymentOf(User $user, string $paymentId): DebtPayment
    {
        return DebtPayment::query()->whereKey($paymentId)->where('user_id', $user->id)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    private function drivenTrip(User $user, string $tripId): ScheduledTrip
    {
        return ScheduledTrip::query()
            ->whereKey($tripId)
            ->whereIn('commute_offer_id', CommuteOffer::query()->where('driver_profile_id', $user->id)->select('id'))
            ->with('tripSession')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    private function vehicleOf(User $user, ?string $vehicleId): Vehicle
    {
        return Vehicle::query()
            ->where('driver_profile_id', $user->id)
            ->when($vehicleId !== null, fn ($q) => $q->whereKey($vehicleId), fn ($q) => $q->orderByDesc('is_active')->orderByDesc('created_at'))
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    /**
     * @return array<string, mixed>
     */
    private function standing(User $user, ?string $vehicleId): array
    {
        $vehicle = Vehicle::query()
            ->where('driver_profile_id', $user->id)
            ->when($vehicleId !== null, fn ($q) => $q->whereKey($vehicleId), fn ($q) => $q->orderByDesc('is_active')->orderByDesc('created_at'))
            ->first();

        return [
            'accountStatus' => strtoupper($user->refresh()->account_status->value),
            'vehicle' => $vehicle === null ? null : [
                'id' => $vehicle->id,
                'verificationStatus' => strtoupper($vehicle->verification_status->value),
            ],
        ];
    }

    private function shiftDebt(User $user, DebtSide $side, int $days, bool $weeks): void
    {
        $columns = $weeks ? ['week_of', 'created_at'] : ['created_at'];

        $this->shift('debt_entries', $columns, fn ($q) => $q->where('user_id', $user->id)->where('side', $side->value), $days);

        if ($weeks) {
            DB::table('debt_entries')
                ->where('user_id', $user->id)
                ->where('side', $side->value)
                ->where('type', DebtEntryType::LateFee->value)
                ->update(['idempotency_key' => DB::raw("CONCAT('late_fee:', `user_id`, ':', `week_of`)")]);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function shift(string $table, array $columns, callable $scope, int $days): void
    {
        $sets = [];

        foreach ($columns as $column) {
            $sets[$column] = DB::raw("DATE_SUB(`{$column}`, INTERVAL {$days} DAY)");
        }

        $scope(DB::table($table))->update($sets);
    }
}
