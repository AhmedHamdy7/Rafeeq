<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Domains\Booking\Actions\DisplaceConfirmedBookingsAction;
use App\Domains\Commute\Actions\CreateCommuteOfferAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Actions\AutoSubmitDriverApplicationAction;
use App\Domains\Driver\Actions\ManageVehicleAction;
use App\Domains\Driver\Actions\UploadVehicleDocumentAction;
use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Payment\Support\DriverFees;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Driver\StoreVehicleRequest;
use App\Http\Requests\Driver\UpdateVehicleRequest;
use App\Http\Requests\Driver\UploadVehicleDocumentRequest;
use App\Http\Resources\VehicleResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VehicleController extends Controller
{
    /**
     * GET /v1/driver/vehicles — Chapter 3 §16 story 5: several vehicles, one
     * of them active.
     */
    public function index(Request $request): JsonResponse
    {
        $profile = DriverProfile::query()->whereKey($request->user()->id)->first();

        $vehicles = $profile === null ? collect() : $profile
            ->vehicles()
            ->with('documents')
            ->orderByDesc('is_active')
            ->get();

        return ApiResponse::success(VehicleResource::collection($vehicles));
    }

    /**
     * POST /v1/driver/vehicles — Chapter 3 §7.
     */
    #[ApiErrors(
        ErrorCode::VehicleLocked,
        ErrorCode::DriverDuplicateDetected,
        ErrorCode::VehicleLimitReached,
        ErrorCode::NotFound,
    )]
    public function store(StoreVehicleRequest $request, ManageVehicleAction $action, AutoSubmitDriverApplicationAction $autoSubmit): JsonResponse
    {
        $vehicle = $action->create($this->profile($request), $request->validated());
        $autoSubmit->attempt($request->user());

        return ApiResponse::success(new VehicleResource($vehicle->load('documents')), status: 201);
    }

    /**
     * PATCH /v1/driver/vehicles/{vehicle}.
     */
    #[ApiErrors(
        ErrorCode::VehicleLocked,
        ErrorCode::DriverDuplicateDetected,
        ErrorCode::NotFound,
    )]
    public function update(UpdateVehicleRequest $request, string $vehicle, ManageVehicleAction $action, AutoSubmitDriverApplicationAction $autoSubmit): JsonResponse
    {
        $profile = $this->profile($request);

        $updated = $action->update($profile, $this->ownedVehicle($profile, $vehicle), $request->validated());
        $autoSubmit->attempt($request->user());

        return ApiResponse::success(new VehicleResource($updated->load('documents')));
    }

    /**
     * POST /v1/driver/vehicles/{vehicle}/activate — Chapter 3 §16 story 5:
     * commutes follow whichever vehicle is active.
     */
    #[ApiErrors(
        ErrorCode::NotFound,
        // An unapproved replacement.
        ErrorCode::VehicleNotAllowed,
        // C-3: a replacement whose approved seats are fewer than a commute moving onto it offers,
        // or one nobody has reviewed yet.
        ErrorCode::CommuteSeatsExceedApproved,
        ErrorCode::VehicleSeatsNotApproved,
        ErrorCode::ValidationFailed,
    )]
    public function activate(Request $request, string $vehicle, ManageVehicleAction $action): JsonResponse
    {
        $profile = $this->profile($request);

        $activated = $action->activate($profile, $this->ownedVehicle($profile, $vehicle));

        return ApiResponse::success(new VehicleResource($activated->load('documents')));
    }

    /**
     * POST /v1/driver/vehicles/{vehicle}/documents — Chapter 3 §8.
     */
    #[ApiErrors(
        ErrorCode::VehicleLocked,
        ErrorCode::DocumentRejectedByScanner,
        ErrorCode::DocumentUnreadable,
        ErrorCode::DocumentDimensionsTooLarge,
        ErrorCode::NotFound,
    )]
    public function storeDocument(
        UploadVehicleDocumentRequest $request,
        string $vehicle,
        UploadVehicleDocumentAction $action,
        AutoSubmitDriverApplicationAction $autoSubmit,
    ): JsonResponse {
        $profile = $this->profile($request);
        $target = $this->ownedVehicle($profile, $vehicle);

        $action->execute(
            owner: $request->user(),
            profile: $profile,
            vehicle: $target,
            type: VehicleDocumentType::from($request->validated('type')),
            file: $request->file('file'),
            expiresAt: $request->validated('expiresAt'),
        );

        $autoSubmit->attempt($request->user());

        return ApiResponse::success(new VehicleResource($target->load('documents')), status: 201);
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function replacePreview(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        $target = $request->query('vehicle');

        $replaces = $profile->vehicles()
            ->where('is_active', true)
            ->when(is_string($target) && $target !== '', fn ($query) => $query->whereKeyNot($target))
            ->exists();

        $bookings = collect();

        if ($replaces) {
            $offers = CommuteOffer::query()
                ->where('driver_profile_id', $profile->user_id)
                ->whereNot('status', CommuteOfferStatus::Archived->value)
                ->get();

            $bookings = $offers->flatMap(fn (CommuteOffer $offer) => DisplaceConfirmedBookingsAction::futureConfirmedBookings($offer));
        }

        return ApiResponse::success([
            'affectedBookings' => $bookings->count(),
            'affectedCommutes' => $bookings->pluck('scheduledTrip.commute_offer_id')->filter()->unique()->count(),
            ...DriverFees::preview($bookings),
            'blocked' => is_string($target) && $target !== '' ? $this->blocked($profile, $target) : null,
        ]);
    }

    /**
     * Why activating this car would be refused, before the driver confirms — the same checks
     * `activate` makes, in the same order, so the sheet never promises a switch that then fails.
     *
     * @return array{code: string, commuteIds: list<string>, approvedSeats: int|null}|null
     */
    private function blocked(DriverProfile $profile, string $vehicleId): ?array
    {
        $vehicle = $profile->vehicles()->whereKey($vehicleId)->first();

        if ($vehicle === null) {
            return null;
        }

        if ($vehicle->verification_status !== VehicleVerificationStatus::Approved) {
            return ['code' => ErrorCode::VehicleNotAllowed->value, 'commuteIds' => [], 'approvedSeats' => $vehicle->approved_seats];
        }

        $moving = CommuteOffer::query()
            ->where('driver_profile_id', $profile->user_id)
            ->whereIn('vehicle_id', $profile->vehicles()->where('is_active', true)->whereKeyNot($vehicle->id)->pluck('id'))
            ->whereNot('status', CommuteOfferStatus::Archived->value)
            ->orderBy('id')
            ->get();

        $code = null;
        $commuteIds = [];

        foreach ($moving as $offer) {
            try {
                CreateCommuteOfferAction::assertCommuteFitsVehicle($offer, $vehicle);
            } catch (DomainException $refusal) {
                $code ??= $refusal->errorCode->value;
                $commuteIds[] = (string) $offer->id;
            }
        }

        return $code === null ? null : ['code' => $code, 'commuteIds' => $commuteIds, 'approvedSeats' => $vehicle->approved_seats];
    }

    private function profile(Request $request): DriverProfile
    {
        return DriverProfile::query()->whereKey($request->user()->id)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    /**
     * Scoped to the caller's own vehicles, and 404 for anyone else's — a 403
     * would confirm the id exists, which is an enumeration oracle (Bible §6).
     */
    private function ownedVehicle(DriverProfile $profile, string $vehicleId): Vehicle
    {
        return $profile->vehicles()->whereKey($vehicleId)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
