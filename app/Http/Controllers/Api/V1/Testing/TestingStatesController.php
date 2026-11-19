<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Actions\RespondToSeatRequestAction;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Actions\CancelTripDayAction;
use App\Domains\Commute\Actions\ProposeCommuteChangeAction;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Actions\StartDriverApplicationAction;
use App\Domains\Driver\Actions\SubmitDriverApplicationAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Enums\VehicleRejectedItem;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Driver\Support\DriverChecklist;
use App\Domains\Driver\Support\DriverEligibility;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Domains\Shared\Testing\DriverBlocker;
use App\Domains\Verification\Actions\RecomputeTrustLevelAction;
use App\Domains\Verification\Actions\ReviewVerificationAction;
use App\Domains\Verification\Enums\DocumentKind;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\IdentityDocument;
use App\Domains\Verification\Models\UserVerification;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\ChangeProposalResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class TestingStatesController extends Controller
{
    private const array APPLICATION_STATES = ['not_started', 'in_progress', 'ready_to_submit', 'submitted', 'under_review', 'action_required'];

    private const array ACTION_ITEMS = ['government_id', 'selfie', 'driving_licence', 'vehicle_registration', 'vehicle_details', 'application'];

    private const array DRIVER_TYPES = [VerificationType::GovernmentId, VerificationType::Selfie, VerificationType::DrivingLicence];

    #[ApiErrors(ErrorCode::NotFound, ErrorCode::ChangeProposalNotLive, ErrorCode::ServiceUnavailable)]
    public function expireProposal(Request $request, string $proposal): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $target = ChangeProposal::query()->whereKey($proposal)->with('commuteOffer')->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $isDriver = (string) $target->commuteOffer?->driver_profile_id === (string) $user->id;
        $isPassenger = Booking::query()
            ->where('passenger_user_id', $user->id)
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()->where('commute_offer_id', $target->commute_offer_id)->select('id'))
            ->exists();

        if (! $isDriver && ! $isPassenger) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        if (! $target->status->isLive()) {
            throw DomainException::of(ErrorCode::ChangeProposalNotLive, fields: [
                'status' => [strtoupper($target->status->value)],
            ]);
        }

        if ($target->expires_at->isFuture()) {
            $target->forceFill(['expires_at' => now()->subSecond()])->save();
        }

        ProposeCommuteChangeAction::expireOverdue($target->id);

        return ApiResponse::success(new ChangeProposalResource($target->fresh()->load('scheduledTrip')));
    }

    #[ApiErrors(ErrorCode::NotFound, ErrorCode::SeatRequestNotPending, ErrorCode::ValidationFailed, ErrorCode::ServiceUnavailable)]
    public function closeSeatRequest(Request $request, string $seatRequest): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $request->validate([
            'status' => ['required', 'string', Rule::in([strtoupper(SeatRequestStatus::ClosedDatePassed->value)])],
        ]);

        $target = SeatRequest::query()
            ->whereKey($seatRequest)
            ->where(fn ($query) => $query
                ->where('passenger_user_id', $user->id)
                ->orWhereIn('commute_offer_id', CommuteOffer::query()->where('driver_profile_id', $user->id)->select('id')))
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $open = [SeatRequestStatus::Pending, SeatRequestStatus::Waitlisted, SeatRequestStatus::Counteroffered];

        if (! in_array($target->status, $open, true)) {
            throw DomainException::of(ErrorCode::SeatRequestNotPending, fields: [
                'status' => [strtoupper($target->status->value)],
            ]);
        }

        $oneDay = $target->scheduled_trip_id !== null
            && ($target->commitment === SeatRequestCommitment::Trial || $target->status === SeatRequestStatus::Waitlisted);

        if (! $oneDay) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'seatRequest' => ['Only a one-day request closes when its day passes.'],
            ]);
        }

        RespondToSeatRequestAction::closeAsDatePassed([(string) $target->id]);

        $target->refresh();

        return ApiResponse::success([
            'id' => $target->id,
            'status' => strtoupper($target->status->value),
            'scheduledTripId' => $target->scheduled_trip_id,
        ]);
    }

    #[ApiErrors(ErrorCode::NotFound, ErrorCode::TripNotCancellable, ErrorCode::ValidationFailed, ErrorCode::ServiceUnavailable)]
    public function cancelByPlatform(Request $request, string $trip, CancelTripDayAction $cancel): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $reason = (string) $request->validate([
            'reason' => ['required', 'string', 'max:120'],
        ])['reason'];

        $target = ScheduledTrip::query()
            ->whereKey($trip)
            ->where(fn ($query) => $query
                ->whereIn('commute_offer_id', CommuteOffer::query()->where('driver_profile_id', $user->id)->select('id'))
                ->orWhereIn('id', Booking::query()->where('passenger_user_id', $user->id)->select('scheduled_trip_id')))
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $cancelled = $cancel->byPlatform($target, trim($reason));

        return ApiResponse::success([
            'id' => $cancelled->id,
            'status' => strtoupper($cancelled->status->value),
            'cancelledBy' => strtoupper((string) $cancelled->cancelled_by),
            'cancelledReason' => $cancelled->cancelled_reason,
            'bookingsCancelled' => Booking::query()
                ->where('scheduled_trip_id', $cancelled->id)
                ->where('cancel_code', 'commute_stopped')
                ->count(),
        ]);
    }

    #[ApiErrors(ErrorCode::NotFound, ErrorCode::ValidationFailed, ErrorCode::DriverNotEligible, ErrorCode::Conflict, ErrorCode::ServiceUnavailable)]
    public function applicationState(
        Request $request,
        StartDriverApplicationAction $start,
        SubmitDriverApplicationAction $submit,
        ReviewDriverApplicationAction $review,
        ReviewVerificationAction $verifications,
        RecomputeTrustLevelAction $trust,
    ): JsonResponse {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'state' => ['required', 'string', Rule::in(self::APPLICATION_STATES)],
            'count' => ['sometimes', 'integer', 'between:1,4'],
            'item' => ['sometimes', 'nullable', 'string', Rule::in(self::ACTION_ITEMS)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        if (CommuteOffer::query()->where('driver_profile_id', $user->id)->exists()) {
            throw DomainException::of(ErrorCode::Conflict, fields: [
                'commutes' => ['This driver already has commutes. Use a fresh test account.'],
            ]);
        }

        $state = (string) $data['state'];
        $item = (string) ($data['item'] ?? 'application');
        $reason = trim((string) ($data['reason'] ?? '')) ?: 'The photo is too blurry to read. Please upload it again.';

        $reviewer = $state === 'action_required'
            ? (AdminUser::query()->orderBy('created_at')->first() ?? throw DomainException::of(ErrorCode::ServiceUnavailable))
            : null;

        $profile = DriverProfile::query()->whereKey($user->id)->first() ?? $start->execute($user);

        DB::transaction(function () use ($user, $profile, $state, $item, $data): void {
            $this->clearApplication($user, $profile);

            if ($state === 'not_started') {
                return;
            }

            $filled = match ($state) {
                'in_progress' => (int) ($data['count'] ?? 2),
                default => 5,
            };

            $pendingIdentity = $state === 'action_required' && in_array($item, ['government_id', 'selfie'], true) ? $item : null;

            $this->fill($user, $profile, $filled, $pendingIdentity);
        });

        $trust->execute($user->fresh());

        if (in_array($state, ['submitted', 'under_review', 'action_required'], true)) {
            $profile = $submit->submit($profile->fresh());
        }

        if ($reviewer !== null) {
            match ($item) {
                'government_id', 'selfie', 'driving_licence' => $verifications->requestMoreInfo(
                    UserVerification::query()->where('user_id', $user->id)->where('type', $item)->firstOrFail(),
                    $reviewer,
                    $reason,
                ),
                'vehicle_registration', 'vehicle_details' => $review->rejectVehicle(
                    $profile->fresh(),
                    Vehicle::query()->where('driver_profile_id', $user->id)->firstOrFail(),
                    $reviewer,
                    VehicleRejectedItem::from($item),
                    $reason,
                ),
                default => $review->reject($profile->fresh(), $reviewer, $reason),
            };
        }

        return ApiResponse::success(DriverChecklist::for(User::query()->findOrFail($user->id)));
    }

    #[ApiErrors(ErrorCode::NotFound, ErrorCode::ValidationFailed)]
    public function setBlocker(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $kind = (string) $request->validate([
            'kind' => ['required', 'string', Rule::in(['under_age', 'generic'])],
        ])['kind'];

        $kind === 'under_age' ? DriverBlocker::underAge($user) : DriverBlocker::generic($user);

        return ApiResponse::success(DriverEligibility::for(User::query()->findOrFail($user->id)));
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function clearBlocker(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        DriverBlocker::clear($user);

        return ApiResponse::success(DriverEligibility::for(User::query()->findOrFail($user->id)));
    }

    private function clearApplication(User $user, DriverProfile $profile): void
    {
        $vehicles = Vehicle::query()->where('driver_profile_id', $user->id)->pluck('id');

        VehicleDocument::query()->whereIn('vehicle_id', $vehicles)->delete();
        Vehicle::query()->whereIn('id', $vehicles)->delete();

        $verifications = UserVerification::query()
            ->where('user_id', $user->id)
            ->whereIn('type', array_map(fn (VerificationType $type): string => $type->value, self::DRIVER_TYPES))
            ->pluck('id');

        IdentityDocument::query()->whereIn('user_verification_id', $verifications)->delete();
        UserVerification::query()->whereIn('id', $verifications)->delete();

        $profile->forceFill([
            'status' => DriverProfileStatus::Draft->value,
            'rejection_reason' => null,
            'reviewer_id' => null,
            'verified_at' => null,
            'national_id_encrypted' => null,
            'national_id_hash' => null,
            'licence_number_encrypted' => null,
            'licence_number_hash' => null,
            'licence_expiry' => null,
        ])->save();
    }

    private function fill(User $user, DriverProfile $profile, int $filled, ?string $pendingIdentity): void
    {
        foreach ([VerificationType::GovernmentId, VerificationType::Selfie] as $index => $type) {
            if ($filled <= $index) {
                return;
            }

            $pending = $pendingIdentity === $type->value;
            $verification = UserVerification::factory()
                ->when(! $pending, fn ($factory) => $factory->approved())
                ->create(['user_id' => $user->id, 'type' => $type]);

            $kinds = $type === VerificationType::GovernmentId ? [DocumentKind::NationalIdFront, DocumentKind::NationalIdBack] : [DocumentKind::Selfie];

            foreach ($kinds as $kind) {
                IdentityDocument::factory()->create(['user_verification_id' => $verification->id, 'kind' => $kind]);
            }
        }

        if ($filled <= 2) {
            return;
        }

        $profile->national_id = '29'.str_pad((string) random_int(0, 999_999_999_999), 12, '0', STR_PAD_LEFT);
        $profile->licence_number = 'DL-'.random_int(1_000_000, 9_999_999);
        $profile->licence_expiry = now()->addYears(3)->startOfDay();
        $profile->save();

        $licence = UserVerification::factory()->create([
            'user_id' => $user->id,
            'type' => VerificationType::DrivingLicence,
            'status' => VerificationStatus::NotStarted,
        ]);

        foreach ([DocumentKind::LicenceFront, DocumentKind::LicenceBack] as $kind) {
            IdentityDocument::factory()->create(['user_verification_id' => $licence->id, 'kind' => $kind]);
        }

        if ($filled <= 3) {
            return;
        }

        $vehicle = Vehicle::factory()->unreviewed()->create([
            'driver_profile_id' => $user->id,
            'verification_status' => VehicleVerificationStatus::Pending,
        ]);

        if ($filled <= 4) {
            return;
        }

        VehicleDocument::factory()->create([
            'vehicle_id' => $vehicle->id,
            'type' => VehicleDocumentType::Registration,
            'verification_status' => VehicleVerificationStatus::Pending,
        ]);
    }
}
