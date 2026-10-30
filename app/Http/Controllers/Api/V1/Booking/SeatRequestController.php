<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Domains\Booking\Actions\ApproveSeatRequestAction;
use App\Domains\Booking\Actions\CounterofferAction;
use App\Domains\Booking\Actions\RequestSeatAction;
use App\Domains\Booking\Actions\RespondToSeatRequestAction;
use App\Domains\Booking\Actions\SetStandbyAvailabilityAction;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Booking\CounterofferSeatRequestRequest;
use App\Http\Requests\Booking\RequestSeatRequest;
use App\Http\Requests\Booking\SetStandbyAvailabilityRequest;
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
        /*
         * Asking a car that is already moving (Feature 03 v5 §148). `error.fields.reachability`
         * says which: the car has passed her, the detour no longer fits before the promised
         * arrival, or nothing can place the car at all.
         */
        ErrorCode::PickupUnreachable,
        /*
         * Her two required points can each be refused (Feature 03 v5 §2): the same place twice,
         * the wrong way round for this commute, a suggestion this commute does not advertise, or a
         * pair that together takes the driver further out of her way than she agreed to.
         */
        ErrorCode::SeatRequestPointsSame,
        ErrorCode::SeatRequestPointsOutOfOrder,
        ErrorCode::SeatRequestSuggestionUnknown,
        ErrorCode::PickupDetourTooLong,
        ErrorCode::PickupNotOnCommute,
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
        /*
         * Inside the 16-hour lock, a rider whose stops would land the run past the arrival the
         * people on board were promised is refused rather than carried (v5: "New bookings after
         * lock only when compatible with frozen commitments").
         */
        ErrorCode::BookingBreaksFrozenPromise,
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
     * POST /v1/driver/seat-requests/{seatRequest}/waitlist — screen 28's third button.
     *
     * "I would take you, but not this week." Without it the driver's only alternative to
     * yes was no, so a full week meant refusing somebody they had already judged suitable.
     */
    #[ApiErrors(ErrorCode::SeatRequestNotPending, ErrorCode::WaitlistFull, ErrorCode::NotFound)]
    public function waitlist(Request $request, string $seatRequest, RespondToSeatRequestAction $action): JsonResponse
    {
        $parked = $action->waitlist(
            $this->forMyCommute($request, $seatRequest),
            $request->user(),
            $request->input('note'),
        );

        return ApiResponse::success(new SeatRequestResource($parked));
    }

    /**
     * POST /v1/driver/seat-requests/{seatRequest}/counteroffer — the driver's third answer.
     *
     * 🔴 Not an approval and not a refusal: "I can take you, from somewhere else." Feature 03 v5 §2
     * makes this one of the driver's three explicit answers, and makes the passenger's acceptance a
     * structured act — *"chat is not consent"*. Nothing about the agreed meeting point moves until
     * she accepts.
     *
     * Either point, or both. The pair is judged by the same rules as a passenger's own: two
     * different places, the right way round for the commute, and inside the detour limit the driver
     * herself set.
     */
    #[ApiErrors(
        ErrorCode::SeatRequestNotPending,
        ErrorCode::SeatRequestPointsSame,
        ErrorCode::SeatRequestPointsOutOfOrder,
        ErrorCode::SeatRequestSuggestionUnknown,
        ErrorCode::PickupDetourTooLong,
        ErrorCode::PickupNotOnCommute,
        ErrorCode::NotFound,
    )]
    public function counteroffer(
        CounterofferSeatRequestRequest $request,
        string $seatRequest,
        CounterofferAction $action,
    ): JsonResponse {
        $countered = $action->propose(
            $this->forMyCommute($request, $seatRequest),
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success(new SeatRequestResource($countered));
    }

    /**
     * POST /v1/seat-requests/{seatRequest}/counteroffer/accept — the passenger agreeing to it.
     *
     * 🔴 This confirms the seat. The driver proposed the place, so she has already said yes on
     * those terms, and asking her again would leave the passenger waiting a second time for an
     * answer that was given. It still runs through the locked approval, because the counteroffer
     * promised a MEETING PLACE and never that a seat would still be free — so `SEAT_UNAVAILABLE`
     * is a real outcome here.
     */
    #[ApiErrors(
        ErrorCode::SeatRequestNotPending,
        ErrorCode::SeatUnavailable,
        ErrorCode::AlreadyBooked,
        ErrorCode::BookingDeadlinePassed,
        ErrorCode::BookingBreaksFrozenPromise,
        ErrorCode::NotFound,
    )]
    public function acceptCounteroffer(Request $request, string $seatRequest, CounterofferAction $action): JsonResponse
    {
        $approval = $action->accept($this->mine($request, $seatRequest), $request->user());

        return ApiResponse::success(new SeatApprovalResource($approval), status: 201);
    }

    /**
     * POST /v1/seat-requests/{seatRequest}/counteroffer/decline — the passenger refusing it.
     *
     * Closes the conversation with its own status, so the refusal lands on neither person's record
     * as something they did not do. See {@see SeatRequestStatus}.
     */
    #[ApiErrors(ErrorCode::SeatRequestNotPending, ErrorCode::NotFound)]
    public function declineCounteroffer(Request $request, string $seatRequest, CounterofferAction $action): JsonResponse
    {
        $declined = $action->decline($this->mine($request, $seatRequest), $request->user());

        return ApiResponse::success(new SeatRequestResource($declined));
    }

    /**
     * PATCH /v1/seat-requests/{seatRequest}/availability — "I can't ride today".
     *
     * ⚠️ v5 proposes this at `PATCH /waiting-list-memberships/{id}/availability`. There is no such
     * resource here: a waiting-list membership **is** a seat request the driver answered with
     * `Waitlist`, and the id a client holds is that request's. A second path onto one row would
     * have the mobile team guessing which id belongs to which URL — and v5 itself asks us to
     * "reuse compatible conventions, do not duplicate APIs".
     *
     * It governs **today only** and nothing else. Her membership, her position and every other day
     * she is waiting for are untouched, there is no penalty, and tomorrow the switch is clear again
     * without anybody sending anything.
     */
    #[ApiErrors(ErrorCode::SeatRequestNotWaitlisted, ErrorCode::NotFound)]
    public function availability(
        SetStandbyAvailabilityRequest $request,
        string $seatRequest,
        SetStandbyAvailabilityAction $action,
    ): JsonResponse {
        $updated = $action->execute(
            $this->mine($request, $seatRequest),
            (bool) $request->validated('available'),
        );

        return ApiResponse::success(new SeatRequestResource($updated));
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
     * The caller's OWN request, as a passenger. 404 for anybody else's, for the same reason as
     * below: a 403 would confirm the id exists, and a seat request names who wants to ride with
     * whom.
     */
    private function mine(Request $request, string $seatRequestId): SeatRequest
    {
        return SeatRequest::query()
            ->whereKey($seatRequestId)
            ->where('passenger_user_id', $request->user()->id)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
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
