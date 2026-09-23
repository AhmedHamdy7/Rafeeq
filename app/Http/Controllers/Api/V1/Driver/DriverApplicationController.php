<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Domains\Driver\Actions\RecordLicenceDetailsAction;
use App\Domains\Driver\Actions\StartDriverApplicationAction;
use App\Domains\Driver\Actions\SubmitDriverApplicationAction;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Support\DriverEligibility;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Driver\RecordLicenceRequest;
use App\Http\Resources\DriverApplicationResource;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DriverApplicationController extends Controller
{
    /**
     * GET /v1/driver/eligibility — Chapter 3 §2/§4.
     *
     * Reports every unmet requirement with the step that clears it, so the
     * "Become a Driver" screen can explain itself rather than just refusing.
     */
    public function eligibility(Request $request): JsonResponse
    {
        return ApiResponse::success(DriverEligibility::for($request->user()));
    }

    /**
     * GET /v1/driver/application — status, what is on file, what is missing.
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            new DriverApplicationResource($this->application($request)->load('vehicles.documents'))
        );
    }

    /**
     * POST /v1/driver/application — opens the application (Chapter 3 §3).
     */
    #[ApiErrors(ErrorCode::DriverNotEligible)]
    public function store(Request $request, StartDriverApplicationAction $action): JsonResponse
    {
        $profile = $action->execute($request->user());

        return ApiResponse::success(
            new DriverApplicationResource($profile->load('vehicles.documents')),
            status: 201,
        );
    }

    /**
     * PUT /v1/driver/application/licence — Chapter 3 §6.
     */
    #[ApiErrors(
        ErrorCode::DriverApplicationLocked,
        ErrorCode::DriverDuplicateDetected,
        ErrorCode::LicenceExpired,
        ErrorCode::NotFound,
    )]
    public function updateLicence(RecordLicenceRequest $request, RecordLicenceDetailsAction $action): JsonResponse
    {
        $profile = $action->execute(
            profile: $this->application($request),
            nationalId: $request->validated('nationalId'),
            licenceNumber: $request->validated('licenceNumber'),
            licenceExpiry: CarbonImmutable::parse($request->validated('licenceExpiry')),
        );

        return ApiResponse::success(new DriverApplicationResource($profile->load('vehicles.documents')));
    }

    /**
     * POST /v1/driver/application/submit — Chapter 3 §9.
     */
    #[ApiErrors(
        ErrorCode::DriverApplicationIncomplete,
        ErrorCode::DriverApplicationLocked,
        ErrorCode::LicenceExpired,
        ErrorCode::NotFound,
    )]
    public function submit(Request $request, SubmitDriverApplicationAction $action): JsonResponse
    {
        $profile = $action->submit($this->application($request));

        return ApiResponse::success([
            'application' => new DriverApplicationResource($profile->load('vehicles.documents')),
            'message' => __('driver.application.submitted'),
        ]);
    }

    /**
     * DELETE /v1/driver/application — withdraws it from review so it can be
     * corrected (Chapter 3 §9). The application is reopened, not deleted:
     * nothing already uploaded is thrown away.
     */
    #[ApiErrors(ErrorCode::DriverApplicationLocked, ErrorCode::NotFound)]
    public function withdraw(Request $request, SubmitDriverApplicationAction $action): JsonResponse
    {
        $profile = $action->withdraw($this->application($request));

        return ApiResponse::success(new DriverApplicationResource($profile->load('vehicles.documents')));
    }

    /**
     * The caller's own application. 404 when there is none — starting one is a
     * different request, and pretending an empty application exists would let
     * the app skip the eligibility checks that `store()` performs.
     */
    private function application(Request $request): DriverProfile
    {
        return DriverProfile::query()->whereKey($request->user()->id)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
