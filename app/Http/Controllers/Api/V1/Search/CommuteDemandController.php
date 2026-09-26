<?php

namespace App\Http\Controllers\Api\V1\Search;

use App\Domains\Matching\Actions\SaveCommuteDemandAction;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Matching\SearchCommutesRequest;
use App\Http\Resources\CommuteDemandResource;
use App\Http\Resources\MatchNotificationResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🔒 Everything here is scoped to the authenticated owner.
 *
 * Chapter 5: "passenger demand is private" and "drivers never browse passenger
 * demands". There is deliberately no index, no search and no filter that could
 * return someone else's saved request — not even to an approved driver, and not
 * even by id. A driver publishes their own journey; the platform tells the
 * passenger. That asymmetry is what stops Rafeeq being an auction on people.
 */
final class CommuteDemandController extends Controller
{
    /**
     * GET /v1/commute-demands — the caller's OWN saved requests.
     */
    public function index(Request $request): JsonResponse
    {
        $demands = CommuteDemand::query()
            ->where('passenger_user_id', $request->user()->id)
            ->latest('created_at')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(CommuteDemandResource::collection($demands));
    }

    /**
     * POST /v1/commute-demands — saves what was searched for when nothing matched
     * (Chapter 5's "Save this commute request?").
     */
    public function store(SearchCommutesRequest $request, SaveCommuteDemandAction $action): JsonResponse
    {
        $demand = $action->execute($request->user(), $request->criteria(), [
            'origin' => $request->input('origin.label'),
            'destination' => $request->input('destination.label'),
        ]);

        return ApiResponse::success(new CommuteDemandResource($demand), status: 201);
    }

    /**
     * DELETE /v1/commute-demands/{demand} — stops it looking for matches.
     *
     * Cancelled, not deleted: the row is why a notification was sent, and erasing
     * it would leave notifications pointing at nothing.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function destroy(Request $request, string $demand, SaveCommuteDemandAction $action): JsonResponse
    {
        $action->cancel($this->owned($request, $demand));

        return ApiResponse::success(['cancelled' => true]);
    }

    /**
     * GET /v1/matches — commutes the platform found for the caller's saved
     * requests since they last looked.
     */
    public function matches(Request $request): JsonResponse
    {
        /*
         * Paged: one row per matching commute per saved demand, written by a background
         * job every time somebody publishes on the corridor. A passenger who saved a
         * request on a busy route collects these faster than any other list in the API.
         */
        $notifications = MatchNotification::query()
            ->whereIn('commute_demand_id', CommuteDemand::query()
                ->where('passenger_user_id', $request->user()->id)
                ->select('id'))
            ->with([
                'commuteOffer.vehicle',
                'commuteOffer.driverProfile.user',
                // The route and the departure time — see MatchNotificationResource for
                // why a notification without them names no commute in particular.
                'commuteOffer.locations',
                'commuteOffer.schedule',
            ])
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated(
            $notifications,
            MatchNotificationResource::collection($notifications->items()),
        );
    }

    /**
     * Scoped to the caller's own demand. 404 for anyone else's — a 403 would
     * confirm the id exists, and for a table this private even that is too much.
     */
    private function owned(Request $request, string $demandId): CommuteDemand
    {
        return CommuteDemand::query()
            ->where('passenger_user_id', $request->user()->id)
            ->whereKey($demandId)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
