<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Actions\ReviewVehicleAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Actions\DeleteAccountAction;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Verification\Actions\ReviewVerificationAction;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TestingController extends Controller
{
    #[ApiErrors(ErrorCode::ServiceUnavailable, ErrorCode::DriverApplicationLocked, ErrorCode::DriverApplicationIncomplete, ErrorCode::LicenceExpired, ErrorCode::NotFound)]
    public function approveDriver(
        Request $request,
        ReviewVerificationAction $verifications,
        ReviewVehicleAction $vehicles,
        ReviewDriverApplicationAction $drivers,
    ): JsonResponse {
        $user = TestAccounts::assertAllowed($request->user());

        $reviewer = AdminUser::query()->orderBy('created_at')->first()
            ?? throw DomainException::of(ErrorCode::ServiceUnavailable);

        $profile = DriverProfile::query()->whereKey($user->id)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        UserVerification::query()
            ->where('user_id', $user->id)
            ->where('status', VerificationStatus::Pending->value)
            ->where('type', '!=', VerificationType::DrivingLicence->value)
            ->get()
            ->each(fn (UserVerification $verification) => $verifications->approve($verification, $reviewer, notify: false));

        $profile->vehicles()->get()->each(fn (Vehicle $vehicle) => $vehicles->execute(
            $vehicle,
            $reviewer,
            max(1, min((int) ($vehicle->seats ?? 4), (int) config('rafeeq.driver.max_approved_seats'))),
            VehicleTier::Comfort,
            true,
        ));

        $profile->refresh();

        if ($profile->status !== DriverProfileStatus::Approved) {
            $drivers->approve($profile, $reviewer);
        }

        return ApiResponse::success([
            'driverStatus' => strtoupper($profile->refresh()->status->value),
            'vehicles' => $profile->vehicles()->get()->map(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id,
                'approvedSeats' => $vehicle->approved_seats,
                'tier' => strtoupper($vehicle->tierOrDefault()->value),
                'verificationStatus' => strtoupper($vehicle->verification_status->value),
            ])->all(),
        ]);
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function destroyAccount(Request $request, DeleteAccountAction $action): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $action->execute($user, skipChecks: true);

        return ApiResponse::success(['deleted' => true]);
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function moveDeparture(Request $request, string $trip): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'departureAt' => ['required_without:minutesFromNow', 'nullable', 'date'],
            'minutesFromNow' => ['required_without:departureAt', 'nullable', 'integer', 'between:-1440,20160'],
        ]);

        $occurrence = ScheduledTrip::query()
            ->whereKey($trip)
            ->whereIn('commute_offer_id', CommuteOffer::query()->where('driver_profile_id', $user->id)->select('id'))
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $target = isset($data['departureAt'])
            ? CarbonImmutable::parse($data['departureAt'])
            : CarbonImmutable::now()->addMinutes((int) $data['minutesFromNow'])->startOfMinute();

        $shift = (int) CarbonImmutable::instance($occurrence->departure_at)->diffInSeconds($target, false);

        DB::transaction(function () use ($occurrence, $shift): void {
            $departure = CarbonImmutable::instance($occurrence->departure_at)->addSeconds($shift);
            $local = $departure->setTimezone('Africa/Cairo');

            $occurrence->forceFill([
                'departure_at' => $departure,
                'departure_local' => $local->format('Y-m-d H:i:s'),
                'trip_date' => $local->toDateString(),
                'promised_arrival_at' => $occurrence->promised_arrival_at === null
                    ? null
                    : CarbonImmutable::instance($occurrence->promised_arrival_at)->addSeconds($shift),
            ])->save();

            NavigationStop::query()
                ->where('scheduled_trip_id', $occurrence->id)
                ->whereNotNull('eta_at')
                ->get()
                ->each(fn (NavigationStop $stop) => $stop->forceFill([
                    'eta_at' => CarbonImmutable::instance($stop->eta_at)->addSeconds($shift),
                ])->save());

            ScheduledTrip::query()->whereKey($occurrence->id)->increment('itinerary_version');
        });

        $occurrence->refresh();

        return ApiResponse::success([
            'tripId' => $occurrence->id,
            'departureAt' => $occurrence->departure_at->toIso8601String(),
            'promisedArrivalAt' => $occurrence->promised_arrival_at?->toIso8601String(),
        ]);
    }
}
