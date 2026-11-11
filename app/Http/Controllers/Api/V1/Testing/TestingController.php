<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Commute\Enums\ScheduledTripStatus;
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
use App\Http\Requests\Testing\MoveDepartureRequest;
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

    #[ApiErrors(ErrorCode::TripDayTaken, ErrorCode::NotFound)]
    public function moveDeparture(MoveDepartureRequest $request, string $trip): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validated();

        $occurrence = ScheduledTrip::query()
            ->whereKey($trip)
            ->whereIn('commute_offer_id', CommuteOffer::query()->where('driver_profile_id', $user->id)->select('id'))
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $target = isset($data['departureAt'])
            ? CarbonImmutable::parse($data['departureAt'])
            : CarbonImmutable::now()->addMinutes((int) $data['minutesFromNow'])->startOfMinute();

        $shift = (int) CarbonImmutable::instance($occurrence->departure_at)->diffInSeconds($target, false);
        $targetDate = $target->setTimezone(self::TIMEZONE)->toDateString();
        $oldDate = $occurrence->trip_date->toDateString();

        $blocker = $targetDate === $oldDate ? null : ScheduledTrip::query()
            ->where('commute_offer_id', $occurrence->commute_offer_id)
            ->whereDate('trip_date', $targetDate)
            ->whereKeyNot($occurrence->getKey())
            ->first();

        if ($blocker !== null && ! in_array($blocker->status, self::MOVABLE, true)) {
            throw DomainException::of(ErrorCode::TripDayTaken, fields: [
                'tripId' => [(string) $blocker->getKey()],
                'tripDate' => [$targetDate],
                'status' => [strtoupper($blocker->status->value)],
            ]);
        }

        DB::transaction(function () use ($occurrence, $shift, $blocker, $oldDate, $targetDate): void {
            if ($blocker !== null) {
                $occurrence->forceFill(['trip_date' => self::PARKING_DATE])->save();

                $days = $blocker->status === ScheduledTripStatus::Scheduled
                    ? (int) CarbonImmutable::parse($targetDate)->diffInDays(CarbonImmutable::parse($oldDate), false)
                    : -$this->daysBackToAFreeDate($blocker);

                $this->shiftTrip($blocker, $days * 86400);
            }

            $this->shiftTrip($occurrence, $shift);
        });

        $occurrence->refresh();

        return ApiResponse::success([
            'tripId' => $occurrence->id,
            'departureAt' => $occurrence->departure_at->toIso8601String(),
            'promisedArrivalAt' => $occurrence->promised_arrival_at?->toIso8601String(),
            'swappedTrip' => $blocker === null ? null : [
                'tripId' => $blocker->id,
                'departureAt' => $blocker->refresh()->departure_at->toIso8601String(),
            ],
        ]);
    }

    private const string TIMEZONE = 'Africa/Cairo';

    private const string PARKING_DATE = '1970-01-01';

    private const array MOVABLE = [
        ScheduledTripStatus::Scheduled,
        ScheduledTripStatus::Completed,
        ScheduledTripStatus::Cancelled,
        ScheduledTripStatus::Expired,
    ];

    private function daysBackToAFreeDate(ScheduledTrip $trip): int
    {
        $taken = ScheduledTrip::query()
            ->where('commute_offer_id', $trip->commute_offer_id)
            ->pluck('trip_date')
            ->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())
            ->all();

        $days = 1;

        while (in_array($trip->trip_date->copy()->subDays($days)->toDateString(), $taken, true)) {
            $days++;
        }

        return $days;
    }

    private function shiftTrip(ScheduledTrip $trip, int $seconds): void
    {
        $departure = CarbonImmutable::instance($trip->departure_at)->addSeconds($seconds);
        $local = $departure->setTimezone(self::TIMEZONE);

        $trip->forceFill([
            'departure_at' => $departure,
            'departure_local' => $local->format('Y-m-d H:i:s'),
            'trip_date' => $local->toDateString(),
            'promised_arrival_at' => $trip->promised_arrival_at === null
                ? null
                : CarbonImmutable::instance($trip->promised_arrival_at)->addSeconds($seconds),
        ])->save();

        NavigationStop::query()
            ->where('scheduled_trip_id', $trip->id)
            ->whereNotNull('eta_at')
            ->get()
            ->each(fn (NavigationStop $stop) => $stop->forceFill([
                'eta_at' => CarbonImmutable::instance($stop->eta_at)->addSeconds($seconds),
            ])->save());

        ScheduledTrip::query()->whereKey($trip->id)->increment('itinerary_version');
    }
}
