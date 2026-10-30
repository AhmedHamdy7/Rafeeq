<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\MemberWarning;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Trip\DisputeWarningRequest;
use App\Http\Resources\MemberWarningResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WarningController extends Controller
{
    /**
     * GET /v1/account/warnings — the warnings recorded about her (Feature 03 v5 §239).
     *
     * > *"Provide clear explanation when warnings displayed, with dispute link."*
     *
     * This is the "review" the notification's deep link opens. Each row carries its own
     * explanation — which warning it was, how many come before a fee is possible, whether this one
     * crossed that line, whether it still stands — so the app is never composing a judgement out
     * of a count.
     *
     * 🔒 **Hers only, and the path takes no parameter.** There is no endpoint at any access level
     * that returns anybody else's: a warning is an accusation, and reading somebody else's would be
     * reading a private record of a time she let people down.
     *
     * ⚠️ A warning not yet delivered is **not** listed. A lateness is recorded at the pickup and
     * held until her drop-off (v5 §10), and showing it here in between would be the "lateness
     * message during the trip" that v5 forbids — through a different door.
     */
    public function index(Request $request): JsonResponse
    {
        $warnings = MemberWarning::query()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('delivered_at')
            ->orderByDesc('occurred_on')
            // Standard #42: a tiebreak, because two warnings can share a day.
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($warnings, MemberWarningResource::collection($warnings->items()), [
            /*
             * How many of each kind still stand, which is the number the escalation actually turns
             * on — and the one a client would otherwise compute from a page of results and get
             * wrong, because an overturned warning is listed and does not count.
             */
            'standing' => [
                'LATE_BOARDING' => MemberWarning::query()
                    ->standing((string) $request->user()->id, WarningKind::LateBoarding)
                    ->whereNotNull('delivered_at')->count(),
                'MISSED_COMMITMENT' => MemberWarning::query()
                    ->standing((string) $request->user()->id, WarningKind::MissedCommitment)
                    ->whereNotNull('delivered_at')->count(),
            ],
        ]);
    }

    /**
     * POST /v1/account/warnings/{warning}/dispute — her answer to it.
     *
     * 🔴 **The dispute link v5 requires**, and the reason it is required rather than nice: a
     * warning is recorded about somebody by somebody else, it escalates to a fee, and the person it
     * is about was not asked. v5 attaches a dispute to both counters, and this is it.
     *
     * 🔒 **Raising it does not pause the escalation.** If it did, every warning would be disputed
     * as a matter of course and the counter would never reach anything. It stands until a reviewer
     * overturns it — and an overturned one stops counting while staying readable, so she can show
     * she was cleared.
     *
     * Once per warning, which is what v5 means by "appeal once". A second attempt at the same
     * decision is a conversation with support rather than another form.
     */
    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function dispute(DisputeWarningRequest $request, string $warning): JsonResponse
    {
        $target = MemberWarning::query()
            ->whereKey($warning)
            ->where('user_id', $request->user()->id)
            // Not yet told about is not yet hers to answer.
            ->whereNotNull('delivered_at')
            ->first()
            // 404 and never 403: a 403 would confirm the id exists, and these ids belong to
            // records about other people's worst mornings.
            ?? throw DomainException::of(ErrorCode::NotFound);

        if ($target->disputed_at !== null) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'dispute' => ['ALREADY_DISPUTED'],
            ]);
        }

        $target->forceFill([
            'disputed_at' => now(),
            'dispute_reason' => mb_substr((string) $request->validated('reason'), 0, 255),
        ])->save();

        return ApiResponse::success(new MemberWarningResource($target));
    }
}
