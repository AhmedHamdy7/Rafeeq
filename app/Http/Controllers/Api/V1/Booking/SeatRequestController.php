<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Domains\Booking\Actions\ApproveSeatRequestAction;
use App\Domains\Booking\Actions\RequestSeatAction;
use App\Domains\Booking\Actions\RespondToSeatRequestAction;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Booking\RequestSeatRequest;
use App\Http\Resources\SeatApprovalResource;
use App\Http\Resources\SeatRequestResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SeatRequestController extends Controller
{
    /**
     * POST /v1/commutes/{commute}/seat-requests — asking a driver for a seat.
     *
     * Rafeeq does not book directly: the driver decides who rides with them, so
     * this creates a question rather than a reservation.
     */
    #[ApiErrors(
        ErrorCode::RulesNotAgreed,
        ErrorCode::AlreadyRequested,
        ErrorCode::AlreadyBooked,
        ErrorCode::SeatUnavailable,
        ErrorCode::BookingDeadlinePassed,
        ErrorCode::WaitlistFull,
        ErrorCode::CannotBookOwnCommute,
        ErrorCode::RecurringDaysNotOffered,
        ErrorCode::NotFound,
    )]
    public function store(RequestSeatRequest $request, string $commute, RequestSeatAction $action): JsonResponse
    {
        // Looked up without any ownership scope: anyone may ask about a published
        // commute. Whether THIS person may is decided by the Action, using the same
        // eligibility rules as the search.
        $offer = CommuteOffer::query()->whereKey($commute)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $seatRequest = $action->execute($request->user(), $offer, $request->validated());

        return ApiResponse::success(new SeatRequestResource($seatRequest), status: 201);
    }

    /**
     * GET /v1/seat-requests — the caller's own requests, as a passenger.
     */
    public function index(Request $request): JsonResponse
    {
        $requests = SeatRequest::query()
            ->where('passenger_user_id', $request->user()->id)
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($requests, SeatRequestResource::collection($requests->items()));
    }

    /**
     * GET /v1/driver/seat-requests — requests waiting for the caller's answer, as a
     * driver.
     */
    public function inbox(Request $request): JsonResponse
    {
        $requests = SeatRequest::query()
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $request->user()->id)
                ->select('id'))
            // PersonSummary needs both: a driver deciding who rides reads the
            // passenger's rating and badges, not just their name.
            ->with('passenger.stats', 'passenger.verifications')
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($requests, SeatRequestResource::collection($requests->items()));
    }

    /**
     * POST /v1/driver/seat-requests/{seatRequest}/approve — seats the passenger.
     *
     * The most concurrency-sensitive operation in the system: the trip row is
     * locked before its seat count is read, so two approvals on the last seat
     * cannot both succeed.
     *
     * One endpoint for both kinds of request. A trial produces one booking; a
     * recurring request produces one for every committed day in the horizon, and
     * names the days it could not seat rather than leaving the driver to find out
     * later.
     */
    #[ApiErrors(
        ErrorCode::SeatUnavailable,
        ErrorCode::SeatRequestNotPending,
        ErrorCode::AlreadyBooked,
        ErrorCode::BookingDeadlinePassed,
        ErrorCode::NotFound,
    )]
    public function approve(Request $request, string $seatRequest, ApproveSeatRequestAction $action): JsonResponse
    {
        $approval = $action->execute($this->forMyCommute($request, $seatRequest), $request->user());

        return ApiResponse::success(new SeatApprovalResource($approval), status: 201);
    }

    /**
     * POST /v1/driver/seat-requests/{seatRequest}/reject.
     */
    #[ApiErrors(ErrorCode::SeatRequestNotPending, ErrorCode::NotFound)]
    public function reject(Request $request, string $seatRequest, RespondToSeatRequestAction $action): JsonResponse
    {
        $rejected = $action->reject(
            $this->forMyCommute($request, $seatRequest),
            $request->user(),
            $request->input('note'),
        );

        return ApiResponse::success(new SeatRequestResource($rejected));
    }

    /**
     * DELETE /v1/seat-requests/{seatRequest} — the passenger withdrawing.
     *
     * Withdrawn rather than rejected, so a driver's refusal rate is not inflated by
     * requests they never saw.
     */
    #[ApiErrors(ErrorCode::SeatRequestNotPending, ErrorCode::NotFound)]
    public function withdraw(Request $request, string $seatRequest, RespondToSeatRequestAction $action): JsonResponse
    {
        $mine = SeatRequest::query()
            ->where('passenger_user_id', $request->user()->id)
            ->whereKey($seatRequest)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        return ApiResponse::success(new SeatRequestResource($action->withdraw($mine)));
    }

    /**
     * A request on one of the caller's OWN commutes. 404 for anybody else's — a 403
     * would confirm the id exists (Bible §6, IDOR).
     */
    private function forMyCommute(Request $request, string $seatRequestId): SeatRequest
    {
        return SeatRequest::query()
            ->whereKey($seatRequestId)
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $request->user()->id)
                ->select('id'))
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
