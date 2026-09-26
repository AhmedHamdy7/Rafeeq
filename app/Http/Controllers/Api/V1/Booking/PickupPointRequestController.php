<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Domains\Booking\Actions\RequestPickupPointAction;
use App\Domains\Booking\Actions\RespondToPickupPointAction;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\PickupDetour;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Models\Place;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Booking\RequestPickupPointRequest;
use App\Http\Requests\Booking\SuggestAlternativePickupRequest;
use App\Http\Resources\PickupPointRequestResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Could you pick me up here instead?", and the driver's answer.
 *
 * 🔒 Every lookup is scoped to the caller's side of the conversation, and a miss is
 * a 404 rather than a 403 — a 403 would confirm that somebody else's pickup request
 * exists.
 */
final class PickupPointRequestController extends Controller
{
    /**
     * POST /v1/seat-requests/{seatRequest}/pickup-request — somebody still joining.
     */
    #[ApiErrors(
        ErrorCode::PickupDetourTooLong,
        ErrorCode::PickupAlreadyRequested,
        ErrorCode::PickupNotOnCommute,
        ErrorCode::SeatRequestNotPending,
        ErrorCode::NotFound,
    )]
    public function storeForSeatRequest(
        RequestPickupPointRequest $request,
        string $seatRequest,
        RequestPickupPointAction $action,
    ): JsonResponse {
        $mine = SeatRequest::query()
            ->where('passenger_user_id', $request->user()->id)
            ->whereKey($seatRequest)
            ->with('commuteOffer.locations')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        return ApiResponse::success(
            new PickupPointRequestResource($action->forSeatRequest($mine, $request->validated())),
            status: 201,
        );
    }

    /**
     * POST /v1/groups/{group}/pickup-request — somebody already riding.
     *
     * ERD §23.1 added `group_member_id` for exactly this screen: the group's
     * "suggest a different meeting point".
     */
    #[ApiErrors(
        ErrorCode::PickupDetourTooLong,
        ErrorCode::PickupAlreadyRequested,
        ErrorCode::PickupNotOnCommute,
        ErrorCode::GroupNotActive,
        ErrorCode::NotFound,
    )]
    public function storeForMember(
        RequestPickupPointRequest $request,
        string $group,
        RequestPickupPointAction $action,
    ): JsonResponse {
        $membership = GroupMember::query()
            ->where('commute_group_id', $group)
            ->where('user_id', $request->user()->id)
            ->with('commuteGroup.commuteOffer.locations')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        return ApiResponse::success(
            new PickupPointRequestResource($action->forMember($membership, $request->validated())),
            status: 201,
        );
    }

    /**
     * GET /v1/driver/pickup-requests — waiting for the caller's answer, as a driver.
     */
    public function inbox(Request $request, GeoQueryEngine $geo): JsonResponse
    {
        $requests = PickupPointRequest::query()
            ->whereIn('id', $this->onMyCommutes($request)->select('pickup_point_requests.id'))
            ->with('seatRequest.commuteOffer.locations', 'groupMember.commuteGroup.commuteOffer.locations')
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        /*
         * The run total is computed per COMMUTE, not per request, and memoised across the
         * page. It costs a routing call and a query over the commute's other approved
         * pickups, and a driver's inbox is usually several requests against one or two
         * commutes — so measuring per row would pay for the same answer repeatedly.
         */
        $runTotals = [];

        $cards = [];

        foreach ($requests->items() as $pickup) {
            $offer = $this->offerOf($pickup);

            if ($offer === null) {
                $cards[] = new PickupPointRequestResource($pickup);

                continue;
            }

            $runTotals[$offer->id] ??= PickupDetour::runTotalFor($geo, $offer);

            $cards[] = (new PickupPointRequestResource($pickup))->withDetourContext(
                $offer->max_detour_minutes,
                // The request's own cost is included, because the driver is deciding
                // whether to ADD it: screen 29 asks "does this keep me within my limit".
                round($runTotals[$offer->id] + (float) $pickup->added_minutes, 1),
            );
        }

        return ApiResponse::paginated($requests, $cards);
    }

    /**
     * The commute a pickup request belongs to, through whichever of its two keys is set.
     */
    private function offerOf(PickupPointRequest $pickup): ?CommuteOffer
    {
        return $pickup->seat_request_id !== null
            ? $pickup->seatRequest?->commuteOffer
            : $pickup->groupMember?->commuteGroup?->commuteOffer;
    }

    /**
     * GET /v1/pickup-requests — the caller's own proposals, as a passenger.
     */
    public function mine(Request $request): JsonResponse
    {
        $requests = PickupPointRequest::query()
            ->where('requested_by_user_id', $request->user()->id)
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($requests, PickupPointRequestResource::collection($requests->items()));
    }

    /**
     * POST /v1/driver/pickup-requests/{pickupRequest}/approve.
     *
     * Applies the point to the requester's upcoming bookings, which is what makes an
     * approval mean anything.
     */
    #[ApiErrors(ErrorCode::PickupRequestNotPending, ErrorCode::NotFound)]
    public function approve(Request $request, string $pickupRequest, RespondToPickupPointAction $action): JsonResponse
    {
        return ApiResponse::success(
            new PickupPointRequestResource($action->approve($this->forMyCommute($request, $pickupRequest)))
        );
    }

    /**
     * POST /v1/driver/pickup-requests/{pickupRequest}/suggest-alternative.
     *
     * A counter-offer. Nothing moves until the passenger accepts it.
     */
    #[ApiErrors(ErrorCode::PickupRequestNotPending, ErrorCode::NotFound)]
    public function suggestAlternative(
        SuggestAlternativePickupRequest $request,
        string $pickupRequest,
        RespondToPickupPointAction $action,
    ): JsonResponse {
        $place = Place::query()->whereKey($request->validated('placeId'))->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        return ApiResponse::success(
            new PickupPointRequestResource(
                $action->suggestAlternative($this->forMyCommute($request, $pickupRequest), $place)
            )
        );
    }

    /**
     * POST /v1/driver/pickup-requests/{pickupRequest}/reject.
     */
    #[ApiErrors(ErrorCode::PickupRequestNotPending, ErrorCode::NotFound)]
    public function reject(Request $request, string $pickupRequest, RespondToPickupPointAction $action): JsonResponse
    {
        return ApiResponse::success(
            new PickupPointRequestResource($action->reject($this->forMyCommute($request, $pickupRequest)))
        );
    }

    /**
     * POST /v1/pickup-requests/{pickupRequest}/accept-alternative — the passenger
     * taking the driver up on their counter-offer.
     */
    #[ApiErrors(ErrorCode::PickupRequestNotPending, ErrorCode::PickupNotOnCommute, ErrorCode::NotFound)]
    public function acceptAlternative(
        Request $request,
        string $pickupRequest,
        RespondToPickupPointAction $action,
    ): JsonResponse {
        $mine = PickupPointRequest::query()
            ->where('requested_by_user_id', $request->user()->id)
            ->whereKey($pickupRequest)
            ->with('alternativePlace', 'seatRequest', 'groupMember.commuteGroup')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        return ApiResponse::success(new PickupPointRequestResource($action->acceptAlternative($mine)));
    }

    /**
     * A pickup request on one of the caller's OWN commutes, reached through either of
     * the two keys the row can carry.
     */
    private function forMyCommute(Request $request, string $pickupRequestId): PickupPointRequest
    {
        return $this->onMyCommutes($request)
            ->where('pickup_point_requests.id', $pickupRequestId)
            ->with('seatRequest', 'groupMember.commuteGroup')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    /**
     * Pickup requests belonging to commutes the caller drives.
     *
     * The row points at either a seat request or a group member, so both paths lead
     * back to the offer and both have to be checked — a query that only followed
     * `seat_request_id` would make every existing member's proposal invisible to the
     * driver it was addressed to.
     *
     * @return Builder<PickupPointRequest>
     */
    private function onMyCommutes(Request $request)
    {
        $myOffers = CommuteOffer::query()
            ->where('driver_profile_id', $request->user()->id)
            ->select('id');

        // Cloned per use: one builder instance compiled into two places accumulates
        // the other's bindings.
        return PickupPointRequest::query()->where(fn ($query) => $query
            ->whereIn('seat_request_id', SeatRequest::query()
                ->whereIn('commute_offer_id', $myOffers->clone())
                ->select('id'))
            ->orWhereIn('group_member_id', GroupMember::query()
                ->whereIn('commute_group_id', CommuteGroup::query()
                    ->whereIn('commute_offer_id', $myOffers->clone())
                    ->select('id'))
                ->select('id')));
    }
}
