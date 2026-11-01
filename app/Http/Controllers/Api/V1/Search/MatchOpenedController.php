<?php

namespace App\Http\Controllers\Api\V1\Search;

use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\MatchNotificationResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/matches/{match}/opened — she tapped a match (HOME-8).
 *
 * 🔴 Why this exists at all. Home's demand alert says "3 new commutes match your demand", and
 * "new" has to mean "she has not looked at it" — otherwise the number only ever grows, and a card
 * that always says 3 is a card nobody reads. `match_notifications.clicked_at` was in the schema
 * from the start; nothing wrote it. This is what writes it.
 *
 * Idempotent: the FIRST open is the one recorded. A second tap is not news, and moving the stamp
 * would lose when she actually first saw it.
 *
 * Its own controller rather than a method on CommuteDemandController, which other work is
 * changing at the same time; the route sits beside `GET /matches` all the same.
 */
final class MatchOpenedController extends Controller
{
    #[ApiErrors(ErrorCode::NotFound)]
    public function __invoke(Request $request, string $match): JsonResponse
    {
        /*
         * 🔒 Scoped to the caller's own demands. 404 for anybody else's — a 403 would confirm the
         * id belongs to somebody, and a match names a commute somebody else asked to be told about.
         */
        $notification = MatchNotification::query()
            ->whereKey($match)
            ->whereIn('commute_demand_id', CommuteDemand::query()
                ->where('passenger_user_id', $request->user()->id)
                ->select('id'))
            ->with([
                'commuteOffer.vehicle',
                'commuteOffer.driverProfile.user',
                'commuteOffer.locations',
                'commuteOffer.schedule',
            ])
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        if ($notification->clicked_at === null) {
            $notification->forceFill(['clicked_at' => now()])->save();
        }

        return ApiResponse::success(new MatchNotificationResource($notification));
    }
}
