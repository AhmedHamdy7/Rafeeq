<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Actions\DeleteAccountAction;
use App\Domains\Identity\Support\AccountDeletionCheck;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deleting her own account (F1-4, PROF-31, D-0028) — a store requirement on both stores.
 *
 * Reachable while suspended, like device management: an account nobody may leave is a trap, and a
 * suspension does not excuse a debt, so the debt rule still applies.
 */
final class AccountDeletionController extends Controller
{
    /**
     * GET /v1/account/deletion — may she delete it now, and if not, why.
     *
     * The same check `DELETE /account` enforces, so the screen can show "Pay EGP 45 first" or
     * "after your trip at 07:30" before she confirms, rather than after.
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(AccountDeletionCheck::for($request->user()));
    }

    /**
     * DELETE /v1/account — delete it. Her upcoming trips are cancelled for free, every session ends,
     * and she is anonymised; the app should sign out locally on 200.
     */
    #[ApiErrors(ErrorCode::AccountHasDebt, ErrorCode::AccountHasTripInFeeWindow)]
    public function destroy(Request $request, DeleteAccountAction $action): JsonResponse
    {
        $action->execute($request->user());

        return ApiResponse::success(['deleted' => true]);
    }
}
