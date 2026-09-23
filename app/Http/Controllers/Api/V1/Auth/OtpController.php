<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domains\Identity\Actions\AuthenticateWithOtpAction;
use App\Domains\Identity\Actions\RequestOtpAction;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\AuthenticationResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class OtpController extends Controller
{
    /**
     * POST /v1/auth/otp/request — Chapter 2 §23.1.
     *
     * Answers the same way for a number with an account and one without.
     */
    #[ApiErrors(
        ErrorCode::TooManyRequests,
        ErrorCode::OtpResendCooldown,
        ErrorCode::OtpMaxResends,
        ErrorCode::OtpDeliveryFailed,
    )]
    public function request(RequestOtpRequest $request, RequestOtpAction $action): JsonResponse
    {
        $result = $action->execute(
            phone: $request->phone(),
            purpose: $request->purpose(),
            ip: $request->ip(),
            devicePublicId: $request->input('device.publicId'),
        );

        return ApiResponse::success([
            'challengeId' => $result['challenge']->id,
            'expiresInSeconds' => $result['expiresInSeconds'],
            'resendAvailableInSeconds' => $result['resendAvailableInSeconds'],
            'maskedPhone' => $result['maskedPhone'],
        ], status: 202);
    }

    /**
     * POST /v1/auth/otp/verify — Chapter 2 §23.2. One endpoint for both
     * registration and sign-in.
     */
    #[ApiErrors(
        ErrorCode::OtpChallengeNotFound,
        ErrorCode::OtpInvalid,
        ErrorCode::OtpExpired,
        ErrorCode::OtpMaxAttempts,
    )]
    public function verify(VerifyOtpRequest $request, AuthenticateWithOtpAction $action): JsonResponse
    {
        $result = $action->execute(
            challengeId: $request->validated('challengeId'),
            code: $request->validated('code'),
            identity: $request->deviceIdentity(),
        );

        return ApiResponse::success(new AuthenticationResource($result));
    }
}
