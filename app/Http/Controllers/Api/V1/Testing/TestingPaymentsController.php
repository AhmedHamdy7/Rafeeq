<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Domains\Shared\Testing\HiddenInstapay;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TestingPaymentsController extends Controller
{
    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function instapay(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $enabled ? HiddenInstapay::show($user->id) : HiddenInstapay::hide($user->id);

        return ApiResponse::success(['enabled' => $enabled]);
    }
}
