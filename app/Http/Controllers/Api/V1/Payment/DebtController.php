<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Payment\Actions\SubmitDebtPaymentAction;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\Coupon;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Support\DebtStanding;
use App\Domains\Payment\Support\SettlementWeek;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Payment\DebtStatementRequest;
use App\Http\Requests\Payment\SubmitDebtPaymentRequest;
use App\Http\Resources\CouponResource;
use App\Http\Resources\DebtEntryResource;
use App\Http\Resources\DebtPaymentResource;
use App\Http\Resources\DebtSideResource;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What she owes RAFEEQ, and paying it (PAY-08 → PAY-17, PAY-34 → PAY-37; Feature 03 backend file
 * L-8 → L-10). Replaces `GET /driver/balance`.
 *
 * 🔒 Her own, always: there is no endpoint at any access level that reads another member's debt.
 *
 * Two debts, never mixed: the DRIVER side (weekly commission, due Friday 23:59 Cairo) and the
 * PASSENGER side (fees, which ride on her next trip as a separate line). One person can have both.
 */
final class DebtController extends Controller
{
    /**
     * GET /v1/account/debt — both sides, her recent payments and coupons.
     *
     * `driver` is null for somebody who has never been a driver.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $hasDriverSide = DriverProfile::query()->whereKey($user->id)->exists()
            || DebtAccount::query()->where('user_id', $user->id)->where('side', DebtSide::Driver->value)->exists();

        return ApiResponse::success([
            'driver' => $hasDriverSide ? new DebtSideResource(DebtStanding::of($user, DebtSide::Driver)) : null,
            'passenger' => new DebtSideResource(DebtStanding::of($user, DebtSide::Passenger)),
            // The last ten "I paid"s, newest first, with what staff decided.
            'payments' => DebtPaymentResource::collection(
                DebtPayment::query()->where('user_id', $user->id)->latest()->limit(10)->get(),
            ),
            // The last ten coupons she was given (PAY-34).
            'coupons' => CouponResource::collection(
                Coupon::query()->where('user_id', $user->id)->latest('created_at')->limit(10)->get(),
            ),
        ]);
    }

    /**
     * GET /v1/account/debt/statements — one week of one side, line by line (PAY-35).
     */
    public function statement(DebtStatementRequest $request): JsonResponse
    {
        $user = $request->user();
        $side = $request->side();

        $weekOf = SettlementWeek::weekOf(
            $request->validated('weekOf') === null
                ? now()
                : CarbonImmutable::parse($request->validated('weekOf'), SettlementWeek::TIMEZONE),
        );

        $lines = DebtEntry::query()
            ->where('user_id', $user->id)
            ->where('side', $side->value)
            ->where('week_of', $weekOf)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'side' => $side->value,
            // The Saturday that starts the week (`Y-m-d`).
            'weekOf' => $weekOf,
            // Its Friday 23:59 Cairo — when this week's commission is due (PAY-11).
            'dueAt' => SettlementWeek::dueAt($weekOf)->utc()->toIso8601String(),
            // Everything that week added to the debt.
            'addedPiastres' => (int) $lines->where('amount_piastres', '>', 0)->sum('amount_piastres'),
            // Everything that week took off it (payments, credits, coupons), as a positive number.
            'takenOffPiastres' => (int) -$lines->where('amount_piastres', '<', 0)->sum('amount_piastres'),
            'lines' => DebtEntryResource::collection($lines),
        ]);
    }

    /**
     * POST /v1/account/debt/payments — "I paid" with InstaPay (PAY-09). `multipart/form-data` when
     * a receipt is attached.
     *
     * Answers 201 with the payment `pending_confirmation`. Nothing comes off her debt until staff
     * see the money (she is notified either way).
     */
    #[ApiErrors(
        ErrorCode::DebtNothingOwed,
        // The receipt: refused by the scan, unreadable, or too large to open.
        ErrorCode::DocumentRejectedByScanner,
        ErrorCode::DocumentUnreadable,
        ErrorCode::DocumentDimensionsTooLarge,
    )]
    public function storePayment(SubmitDebtPaymentRequest $request, SubmitDebtPaymentAction $action): JsonResponse
    {
        $payment = $action->execute(
            $request->user(),
            $request->side(),
            (int) $request->validated('amountPiastres'),
            (string) $request->validated('instapayReference'),
            $request->file('receiptImage'),
        );

        return ApiResponse::success(new DebtPaymentResource($payment), status: 201);
    }
}
