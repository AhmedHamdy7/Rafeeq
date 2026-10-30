<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Reliability\Actions\AppealReliabilityEventAction;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Account\AppealReliabilityEventRequest;
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

    /**
     * POST /v1/account/reliability/events/{event}/appeal — she asks for one to be looked at
     * (Feature 03 v5 §115: a ban comes *"with appeal"*).
     *
     * 🔴 **This was the missing half of a mechanism that already had its outcome.** The ledger has
     * been able to record a reversal since it was built, and nothing could ever write one, because
     * there was no way to file. An appeal that cannot be filed is not an appeal — and a number that
     * can end an account with no way to answer it is precisely what v5 attaches the word to.
     *
     * ⚠️ **Filing changes nothing on its own.** The event keeps counting until a reviewer reverses
     * it, exactly as a disputed warning does: if asking were enough to suspend the consequence,
     * everything would be appealed as a matter of course and the score would stop meaning anything.
     * What it buys her is that a person has to look, and that she is told what they decided.
     *
     * Once per event, which is v5's "appeal once" — `422` with `error.fields.appeal` saying
     * `ALREADY_APPEALED` or `ALREADY_REVERSED`. Somebody else's event answers `404`.
     */
    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function appeal(
        AppealReliabilityEventRequest $request,
        string $event,
        AppealReliabilityEventAction $action,
    ): JsonResponse {
        $target = ReliabilityEvent::query()->whereKey($event)->first()
            // 404 and never 403: a 403 would confirm the id names a real judgement about a real
            // person, which makes an id an oracle for whose record exists.
            ?? throw DomainException::of(ErrorCode::NotFound);

        $appealed = $action->file($request->user(), $target, (string) $request->validated('reason'));

        return ApiResponse::success([
            'reliabilityEventId' => (string) $appealed->getKey(),
            'appealedAt' => $appealed->appealed_at?->toIso8601String(),
            /*
             * Stated back to her, because it is the thing most likely to be misunderstood: she has
             * been heard, and nothing has changed yet.
             */
            'stillCounts' => $appealed->counts(),
        ]);
    }
}
