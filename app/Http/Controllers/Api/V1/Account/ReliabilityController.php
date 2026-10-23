<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReliabilityResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReliabilityController extends Controller
{
    /**
     * GET /v1/account/reliability — her own standing, and every event behind it.
     *
     * 🔴 Feature 03 v5 builds enforcement on this number: a monthly admin review, a temporary
     * suspension, a permanent ban. Somebody whose account can end on a figure has to be able to
     * read the figure and the reasons for it — before the review rather than after it, and without
     * asking support. That is the whole reason this endpoint exists rather than the score living
     * only in an admin screen.
     *
     * Two scores, because one account is one person doing two things and her records are kept
     * apart: a driver's cancellations never reach her passenger standing.
     *
     * 🔒 Only ever about the caller. There is no parameter for whose reliability to read, at any
     * access level — the number is a judgement, and a judgement about somebody else is not
     * something a member may look up.
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(ReliabilityResource::for($request->user()->id));
    }
}
