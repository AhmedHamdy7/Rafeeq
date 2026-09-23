<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Domains\Driver\Actions\ManageVehicleAction;
use App\Domains\Driver\Actions\UploadVehicleDocumentAction;
use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
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
        $vehicles = $this->profile($request)
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
        ErrorCode::DriverApplicationLocked,
        ErrorCode::DriverDuplicateDetected,
        ErrorCode::VehicleLimitReached,
        ErrorCode::NotFound,
    )]
    public function store(StoreVehicleRequest $request, ManageVehicleAction $action): JsonResponse
    {
        $vehicle = $action->create($this->profile($request), $request->validated());

        return ApiResponse::success(new VehicleResource($vehicle->load('documents')), status: 201);
    }

    /**
     * PATCH /v1/driver/vehicles/{vehicle}.
     */
    #[ApiErrors(
        ErrorCode::DriverApplicationLocked,
        ErrorCode::DriverDuplicateDetected,
        ErrorCode::NotFound,
    )]
    public function update(UpdateVehicleRequest $request, string $vehicle, ManageVehicleAction $action): JsonResponse
    {
        $profile = $this->profile($request);

        $updated = $action->update($profile, $this->ownedVehicle($profile, $vehicle), $request->validated());

        return ApiResponse::success(new VehicleResource($updated->load('documents')));
    }

    /**
     * POST /v1/driver/vehicles/{vehicle}/activate — Chapter 3 §16 story 5:
     * commutes follow whichever vehicle is active.
     */
    #[ApiErrors(ErrorCode::NotFound)]
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
        ErrorCode::DriverApplicationLocked,
        ErrorCode::DocumentRejectedByScanner,
        ErrorCode::DocumentUnreadable,
        ErrorCode::NotFound,
    )]
    public function storeDocument(
        UploadVehicleDocumentRequest $request,
        string $vehicle,
        UploadVehicleDocumentAction $action,
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

        return ApiResponse::success(new VehicleResource($target->load('documents')), status: 201);
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
