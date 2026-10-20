<?php

namespace App\Http\Controllers\Api\V1\Commute;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Actions\ProposeCommuteChangeAction;
use App\Domains\Commute\Actions\RespondToChangeProposalAction;
use App\Domains\Commute\Enums\ChangeApprovalResponse;
use App\Domains\Commute\Enums\ChangeProposalStatus;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Commute\ProposeCommuteChangeRequest;
use App\Http\Requests\Commute\RespondToChangeProposalRequest;
use App\Http\Resources\ChangeProposalResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Changing a commute people have already booked, with their agreement (Feature 03 v5 §6).
 *
 * 🔴 **This is the only path by which a published commute's departure or advertised stops change.**
 * Everywhere else the answer is `COMMUTE_NOT_EDITABLE`, because people book specific days on the
 * strength of both. v5 asks for a yes with a condition attached, and the condition is every
 * affected passenger's explicit agreement — so the capability exists here and nowhere else.
 *
 * 🔒 The driver's routes are scoped to her own commutes and the passenger's to her own bookings,
 * both 404 on a miss. A proposal names who rides with whom on which mornings.
 */
final class ChangeProposalController extends Controller
{
    /**
     * POST /v1/driver/commutes/{commute}/change-proposals — ask her passengers.
     *
     * One combined request, as v5 requires: several fields move together because they only make
     * sense together, and there is no partial acceptance of the result.
     */
    #[ApiErrors(
        ErrorCode::ChangeProposalAlreadyOpen,
        ErrorCode::ChangeProposalNobodyAffected,
        ErrorCode::NotFound,
    )]
    public function store(
        ProposeCommuteChangeRequest $request,
        string $commute,
        ProposeCommuteChangeAction $action,
    ): JsonResponse {
        $proposal = $action->propose($this->myCommute($request, $commute), $request->validated());

        return ApiResponse::success(new ChangeProposalResource($proposal), status: 201);
    }

    /**
     * GET /v1/driver/change-proposals — her pending view, per passenger.
     *
     * v5 asks for `Accepted`, `Awaiting Response`, `Rejected`, `Withdrawn` against each affected
     * passenger. "Awaiting" is the absence of an answer, so the roster is built by joining the
     * affected bookings to whatever responses exist — see {@see ChangeProposal::responseRoster()}.
     */
    public function index(Request $request): JsonResponse
    {
        $proposals = ChangeProposal::query()
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $request->user()->id)
                ->select('id'))
            ->with('scheduledTrip')
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated(
            $proposals,
            ChangeProposalResource::forDriver($proposals->items()),
        );
    }

    /**
     * PATCH /v1/driver/change-proposals/{proposal} — change what she is asking for.
     *
     * 🔴 Every approval stops counting. Somebody who agreed to 07:45 has not agreed to 07:30, and
     * carrying her yes across would apply a change she never saw. Warn before sending.
     */
    #[ApiErrors(ErrorCode::ChangeProposalNotLive, ErrorCode::NotFound)]
    public function update(
        ProposeCommuteChangeRequest $request,
        string $proposal,
        ProposeCommuteChangeAction $action,
    ): JsonResponse {
        $revised = $action->revise($this->myProposal($request, $proposal), $request->validated());

        return ApiResponse::success(new ChangeProposalResource($revised));
    }

    /**
     * DELETE /v1/driver/change-proposals/{proposal} — take it back.
     */
    #[ApiErrors(ErrorCode::ChangeProposalNotLive, ErrorCode::NotFound)]
    public function destroy(Request $request, string $proposal, ProposeCommuteChangeAction $action): JsonResponse
    {
        return ApiResponse::success(
            new ChangeProposalResource($action->withdraw($this->myProposal($request, $proposal)))
        );
    }

    /**
     * GET /v1/change-proposals — what the caller is being asked to agree to.
     *
     * Only live proposals on days she is confirmed on, because an answered or expired one is not a
     * decision she has in front of her.
     */
    public function mine(Request $request): JsonResponse
    {
        $proposals = ChangeProposal::query()
            ->whereIn('status', [ChangeProposalStatus::Pending->value, ChangeProposalStatus::Rejected->value])
            ->where('expires_at', '>', now())
            ->whereIn('commute_offer_id', Booking::query()
                ->where('passenger_user_id', $request->user()->id)
                ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
                ->select('scheduled_trips.commute_offer_id'))
            ->with('scheduledTrip')
            ->latest('created_at')
            ->orderByDesc('id')
            ->get()
            // Filtered in PHP rather than SQL: "affected" is defined by the proposal's own scope
            // and weekday, and reimplementing that as a join is how the two definitions drift.
            ->filter(fn (ChangeProposal $proposal): bool => $this->bookingFor($proposal, $request) !== null)
            ->values();

        $items = [];

        foreach ($proposals as $proposal) {
            $items[] = ChangeProposalResource::forPassenger(
                $proposal,
                $this->bookingFor($proposal, $request),
            );
        }

        return ApiResponse::success($items);
    }

    /**
     * POST /v1/change-proposals/{proposal}/respond — accept, reject, or take an acceptance back.
     *
     * 🔴 All of it or none of it. v5: `Accept All Changes` / `Reject`, no partial accept — the
     * pieces of a proposal only make sense together, so agreeing to half would be agreeing to
     * something the driver never offered.
     *
     * The last acceptance applies the change there and then. Everybody has agreed; leaving it
     * unapplied until the driver taps something would mean a commute whose people all consented to
     * 07:30 still departing at 08:00.
     */
    #[ApiErrors(ErrorCode::ChangeProposalNotLive, ErrorCode::NotFound)]
    public function respond(
        RespondToChangeProposalRequest $request,
        string $proposal,
        RespondToChangeProposalAction $action,
    ): JsonResponse {
        $target = ChangeProposal::query()->whereKey($proposal)->with('scheduledTrip')->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        /*
         * 🔒 Her standing to answer comes from a confirmed booking on an affected day, found
         * through her own id — never from the payload. Without that, anybody could answer for
         * anybody and a proposal would apply on strangers' consent.
         */
        $booking = $this->bookingFor($target, $request)
            ?? throw DomainException::of(ErrorCode::NotFound);

        $answered = $action->respond(
            $target,
            $booking,
            ChangeApprovalResponse::from((string) $request->validated('response')),
        );

        return ApiResponse::success(ChangeProposalResource::forPassenger($answered, $booking));
    }

    /**
     * The caller's own confirmed booking on a day this proposal affects, or null.
     */
    private function bookingFor(ChangeProposal $proposal, Request $request): ?Booking
    {
        return $proposal->affectedBookings()
            ->where('passenger_user_id', $request->user()->id)
            ->first();
    }

    private function myCommute(Request $request, string $commuteId): CommuteOffer
    {
        return CommuteOffer::query()
            ->whereKey($commuteId)
            ->where('driver_profile_id', $request->user()->id)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    private function myProposal(Request $request, string $proposalId): ChangeProposal
    {
        return ChangeProposal::query()
            ->whereKey($proposalId)
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $request->user()->id)
                ->select('id'))
            ->with('scheduledTrip')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
