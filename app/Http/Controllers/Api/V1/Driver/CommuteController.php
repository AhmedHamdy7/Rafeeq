<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Commute\Actions\CreateCommuteOfferAction;
use App\Domains\Commute\Actions\PublishCommuteOfferAction;
use App\Domains\Commute\Actions\ReviseCommuteOfferAction;
use App\Domains\Commute\Actions\SaveCommuteRouteAction;
use App\Domains\Commute\Actions\SaveCommuteScheduleAction;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Commute\ReviseCommuteRequest;
use App\Http\Requests\Commute\SaveCommuteRouteRequest;
use App\Http\Requests\Commute\SaveCommuteScheduleRequest;
use App\Http\Requests\Commute\StoreCommuteRequest;
use App\Http\Resources\CommuteOfferResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommuteController extends Controller
{
    /**
     * GET /v1/commutes — the driver's own commutes.
     */
    public function index(Request $request): JsonResponse
    {
        $offers = $this->profile($request)
            ->commuteOffers()
            ->with(['locations', 'schedule', 'rules'])
            // The id is a tiebreak, not decoration: `created_at` has
            // second precision, so two commutes made in the same second have no
            // defined order without it — and an unstable sort is what makes a
            // paginated list repeat or skip rows between pages.
            ->latest('created_at')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(CommuteOfferResource::collection($offers));
    }

    /**
     * GET /v1/commutes/{commute}.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function show(Request $request, string $commute): JsonResponse
    {
        return ApiResponse::success(new CommuteOfferResource(
            $this->owned($request, $commute)->load(['locations', 'schedule', 'rules', 'scheduledTrips'])
        ));
    }

    /**
     * POST /v1/commutes — opens a draft (Chapter 4 steps 1, 2, 5, 6, 7).
     *
     * The route and schedule arrive through their own endpoints, because each is
     * a whole thing that has to be validated together.
     */
    #[ApiErrors(
        ErrorCode::DriverNotEligible,
        ErrorCode::LicenceExpired,
        ErrorCode::CommuteVehicleUnavailable,
        ErrorCode::NotFound,
    )]
    public function store(StoreCommuteRequest $request, CreateCommuteOfferAction $action): JsonResponse
    {
        $offer = $action->execute($this->profile($request), $request->validated());

        return ApiResponse::success(
            new CommuteOfferResource($offer->load(['locations', 'schedule', 'rules'])),
            status: 201,
        );
    }

    /**
     * PATCH /v1/commutes/{commute} — revises the terms (Chapter 4 §5, §6, §7).
     */
    #[ApiErrors(
        ErrorCode::CommuteNotEditable,
        ErrorCode::CommuteSeatsConflict,
        ErrorCode::CommuteVehicleUnavailable,
        ErrorCode::NotFound,
    )]
    public function update(ReviseCommuteRequest $request, string $commute, ReviseCommuteOfferAction $action): JsonResponse
    {
        $offer = $action->execute($this->owned($request, $commute), $request->validated());

        return ApiResponse::success(new CommuteOfferResource(
            $offer->load(['locations', 'schedule', 'rules'])
        ));
    }

    /**
     * PUT /v1/commutes/{commute}/route — Chapter 4 §3.
     *
     * A PUT because the route is replaced as a whole.
     */
    #[ApiErrors(
        ErrorCode::CommuteNotEditable,
        ErrorCode::CommuteRouteInvalid,
        ErrorCode::NotFound,
    )]
    public function saveRoute(SaveCommuteRouteRequest $request, string $commute, SaveCommuteRouteAction $action): JsonResponse
    {
        $offer = $action->execute($this->owned($request, $commute), $request->validated());

        return ApiResponse::success(new CommuteOfferResource(
            $offer->load(['locations', 'schedule', 'rules'])
        ));
    }

    /**
     * PUT /v1/commutes/{commute}/schedule — Chapter 4 §4.
     */
    #[ApiErrors(
        ErrorCode::CommuteNotEditable,
        ErrorCode::CommuteIncomplete,
        ErrorCode::NotFound,
    )]
    public function saveSchedule(SaveCommuteScheduleRequest $request, string $commute, SaveCommuteScheduleAction $action): JsonResponse
    {
        $offer = $action->execute($this->owned($request, $commute), $request->validated());

        return ApiResponse::success(new CommuteOfferResource(
            $offer->load(['locations', 'schedule', 'rules'])
        ));
    }

    /**
     * POST /v1/commutes/{commute}/publish — computes the route, writes the search
     * box, and generates the first bookable days.
     */
    #[ApiErrors(
        ErrorCode::CommuteIncomplete,
        ErrorCode::CommuteInvalidTransition,
        ErrorCode::CommuteVehicleUnavailable,
        ErrorCode::DriverNotEligible,
        ErrorCode::LicenceExpired,
        ErrorCode::NotFound,
    )]
    public function publish(Request $request, string $commute, PublishCommuteOfferAction $action): JsonResponse
    {
        $offer = $action->execute($this->owned($request, $commute));

        return ApiResponse::success(new CommuteOfferResource(
            $offer->load(['locations', 'schedule', 'rules', 'scheduledTrips'])
        ));
    }

    /**
     * POST /v1/commutes/{commute}/pause — stops it being found and stops new days
     * being generated. Days already booked are untouched.
     */
    #[ApiErrors(ErrorCode::CommuteInvalidTransition, ErrorCode::NotFound)]
    public function pause(Request $request, string $commute, ChangeCommuteStatusAction $action): JsonResponse
    {
        $offer = $action->pause($this->owned($request, $commute), CommutePausedReason::ByDriver);

        return ApiResponse::success(new CommuteOfferResource(
            $offer->load(['locations', 'schedule', 'rules'])
        ));
    }

    /**
     * POST /v1/commutes/{commute}/resume — catches up on the days missed while
     * paused. Refused if the vehicle or licence is no longer usable.
     */
    #[ApiErrors(
        ErrorCode::CommuteInvalidTransition,
        ErrorCode::CommuteVehicleUnavailable,
        ErrorCode::DriverNotEligible,
        ErrorCode::LicenceExpired,
        ErrorCode::NotFound,
    )]
    public function resume(Request $request, string $commute, ChangeCommuteStatusAction $action): JsonResponse
    {
        $offer = $action->resume($this->owned($request, $commute));

        return ApiResponse::success(new CommuteOfferResource(
            $offer->load(['locations', 'schedule', 'rules'])
        ));
    }

    /**
     * DELETE /v1/commutes/{commute} — archives it. Nothing is deleted: the
     * history stays, and only future days nobody has taken are cancelled.
     */
    #[ApiErrors(ErrorCode::CommuteInvalidTransition, ErrorCode::NotFound)]
    public function destroy(Request $request, string $commute, ChangeCommuteStatusAction $action): JsonResponse
    {
        $offer = $action->archive($this->owned($request, $commute));

        return ApiResponse::success(new CommuteOfferResource(
            $offer->load(['locations', 'schedule', 'rules'])
        ));
    }

    private function profile(Request $request): DriverProfile
    {
        return DriverProfile::query()->whereKey($request->user()->id)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    /**
     * Scoped to the caller's own commutes, answering 404 for anyone else's — a
     * 403 would confirm the id exists (Bible §6, IDOR).
     */
    private function owned(Request $request, string $commuteId): CommuteOffer
    {
        return CommuteOffer::query()
            ->where('driver_profile_id', $request->user()->id)
            ->whereKey($commuteId)
            ->with('driverProfile')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
