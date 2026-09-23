<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domains\Identity\Actions\RefreshSessionAction;
use App\Domains\Identity\Actions\RevokeSessionAction;
use App\Domains\Identity\Support\CurrentSession;
use App\Domains\Identity\Support\LaunchRouter;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Auth\RefreshSessionRequest;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SessionController extends Controller
{
    /**
     * POST /v1/auth/session/refresh — Chapter 2 §23.3.
     *
     * Unauthenticated by design: it is called precisely when the access
     * token has expired. The refresh token is the credential.
     */
    #[ApiErrors(
        ErrorCode::SessionInvalid,
        ErrorCode::SessionExpired,
        ErrorCode::SessionReuseDetected,
        ErrorCode::DeviceRevoked,
    )]
    public function refresh(RefreshSessionRequest $request, RefreshSessionAction $action): JsonResponse
    {
        $issued = $action->execute($request->validated('refreshToken'));

        return ApiResponse::success([
            'session' => new SessionResource($issued),
            // Re-routed on every refresh, so a suspension applied while the
            // app was open takes effect at the next refresh rather than
            // waiting for a restart (scenario H).
            'nextStep' => LaunchRouter::nextStepFor($issued->session->user, $issued->session->device)->value,
        ]);
    }

    /**
     * GET /v1/auth/me — the launch router's server-side half (§3). Tells the
     * app who it is signed in as and where to go.
     */
    #[ApiErrors(ErrorCode::SessionInvalid)]
    public function me(Request $request): JsonResponse
    {
        $session = CurrentSession::for($request);

        if ($session === null) {
            throw DomainException::of(ErrorCode::SessionInvalid);
        }

        return ApiResponse::success([
            'user' => new UserResource($request->user()),
            'device' => new DeviceResource($session->device),
            'nextStep' => LaunchRouter::nextStepFor($request->user(), $session->device)->value,
        ]);
    }

    /**
     * POST /v1/auth/logout — Chapter 2 §23.4. The account survives; this
     * installation's access does not.
     */
    #[ApiErrors(ErrorCode::SessionInvalid)]
    public function logout(Request $request, RevokeSessionAction $action): JsonResponse
    {
        $session = CurrentSession::for($request);

        if ($session === null) {
            throw DomainException::of(ErrorCode::SessionInvalid);
        }

        $action->logout($session);

        // 200 with the envelope rather than 204: a bodyless success would be
        // the only endpoint in the API the client has to special-case.
        return ApiResponse::success(['revoked' => true]);
    }
}
