<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Actions\AppealSuspensionAction;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Account\AppealSuspensionRequest;
use App\Http\Resources\SuspensionAppealResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class SuspensionAppealController extends Controller
{
    /**
     * POST /v1/account/suspension/appeal — the Appeal button on the suspension banner (AUTH-19,
     * follow-up F1-3).
     *
     * One appeal within 48 hours of the hold; a second only with new `evidence`, within 48 hours
     * of the first being turned down. 201 with the appeal `pending`. `404` when she is not
     * suspended. `422` with `error.fields.appeal`: `APPEAL_PENDING` · `ALREADY_APPEALED` ·
     * `EVIDENCE_REQUIRED` · `APPEAL_WINDOW_CLOSED` · `PERMANENT_BAN`. Overturned → the hold is
     * lifted and she is told.
     */
    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function store(AppealSuspensionRequest $request, AppealSuspensionAction $action): JsonResponse
    {
        $appeal = $action->execute(
            $request->user(),
            (string) $request->validated('message'),
            $request->validated('evidence'),
        );

        return ApiResponse::success(new SuspensionAppealResource($appeal), status: 201);
    }
}
