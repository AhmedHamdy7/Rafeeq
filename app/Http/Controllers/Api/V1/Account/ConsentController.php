<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Actions\RecordConsentAction;
use App\Domains\Identity\Enums\ConsentSource;
use App\Domains\Identity\Support\ConsentRegistry;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consent versioning (Phase 2 scope in the Master Plan).
 *
 * Publishing new terms is a version bump in configuration; every account's
 * consent then reads as outstanding until it is accepted again, and the
 * previous acceptance stays on file as the evidence of what was agreed
 * when.
 */
final class ConsentController extends Controller
{
    /**
     * GET /v1/account/consents — what is in force and what this account
     * still owes.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'currentVersions' => ConsentRegistry::currentVersions(),
            'outstanding' => RecordConsentAction::outstandingFor($request->user()),
        ]);
    }

    /**
     * POST /v1/account/consents — accept the versions currently in force.
     */
    public function store(Request $request, RecordConsentAction $action): JsonResponse
    {
        $action->execute($request->user(), ConsentSource::ForcedReaccept);

        return ApiResponse::success([
            'currentVersions' => ConsentRegistry::currentVersions(),
            'outstanding' => RecordConsentAction::outstandingFor($request->user()),
        ]);
    }
}
