<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Commute\Actions\CreateCommuteOfferAction;
use App\Domains\Commute\Actions\DuplicateCommuteOfferAction;
use App\Domains\Commute\Actions\PublishCommuteOfferAction;
use App\Domains\Commute\Actions\ReviseCommuteOfferAction;
use App\Domains\Commute\Actions\SaveCommuteRouteAction;
use App\Domains\Commute\Actions\SaveCommuteScheduleAction;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Support\FairPriceSuggestion;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
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
        ErrorCode::DriverDebtLimitReached,
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
     * POST /v1/commutes/{commute}/pause — stops it being found, stops new days being generated,
     * and **cancels every future confirmed booking on it**.
     *
     * 🔴 The cancellation is new, and it is the part a client has to handle. Feature 03 v5: *"It
     * cancels ALL future confirmed bookings, not active trip; displaced passengers are notified and
     * automatically added to preserved waiting list."* A run that has already begun is untouched —
     * that is Cancel Active Trip, a different decision with its own fees.
     *
     * Resuming does NOT give the seats back. She offers them to the waiting members she chooses,
     * through `POST /v1/driver/commutes/{commute}/waitlist/offers`.
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
        ErrorCode::DriverDebtLimitReached,
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
     * POST /v1/commutes/{commute}/duplicate — the same journey again (Feature 03 v5 §4, §5).
     *
     * 🔴 **It copies configuration and nobody else.** No booking, no seat request, no waiting list,
     * no group, no generated day — the answer is a **draft** with the route, the terms, the
     * preferences and the weekly pattern, and nothing in it that belongs to a passenger. Say so on
     * the screen: v5 asks us to "explain neither shortcut copies passengers/bookings/waiting list",
     * and a driver who assumes otherwise will publish believing her riders came with her.
     *
     * ⚠️ The date window moves: the start becomes **tomorrow** if the original's has passed, because
     * a schedule may not start in the past and a literal copy would be a draft she could not
     * publish. A window with nothing left in it carries no schedule at all — check `schedule` on
     * the response and send her to the schedule step when it is null.
     *
     * ⚠️ v5 proposes this at `/driver/commutes/{id}/duplicate`. Every other commute-management
     * endpoint in this API lives under `/commutes`, so this does too.
     */
    #[ApiErrors(
        ErrorCode::DriverNotEligible,
        ErrorCode::LicenceExpired,
        ErrorCode::CommuteVehicleUnavailable,
        ErrorCode::ValidationFailed,
        ErrorCode::NotFound,
    )]
    public function duplicate(Request $request, string $commute, DuplicateCommuteOfferAction $action): JsonResponse
    {
        $draft = $action->copy(
            $this->profile($request),
            $this->owned($request, $commute)->load(['locations', 'schedule', 'rules', 'weekdaySchedules']),
        );

        return ApiResponse::success(new CommuteOfferResource($draft), status: 201);
    }

    /**
     * POST /v1/commutes/{commute}/return — the same journey home (Feature 03 v5 §4).
     *
     * The route comes back reversed, and reversed properly: the ends swap, the advertised stops come
     * back in the opposite order, and each one's role flips. A stop where she picks somebody up near
     * home on the way to work is where she **sets her down** on the way back, so a reversal that
     * kept the roles would advertise a journey home that collects people from their own front doors.
     *
     * 🔴 **It carries no schedule, deliberately.** A journey home leaves at a different time of day,
     * and a prefilled 07:05 is a wrong value in the field a driver is least likely to re-read
     * because it already looks filled in. v5: "Reverse route prefill **only**".
     *
     * Same promise as `duplicate` about people: nothing but configuration crosses over.
     */
    #[ApiErrors(
        ErrorCode::DriverNotEligible,
        ErrorCode::LicenceExpired,
        ErrorCode::CommuteVehicleUnavailable,
        ErrorCode::ValidationFailed,
        ErrorCode::NotFound,
    )]
    public function returnTrip(Request $request, string $commute, DuplicateCommuteOfferAction $action): JsonResponse
    {
        $draft = $action->reverse(
            $this->profile($request),
            $this->owned($request, $commute)->load(['locations', 'schedule', 'rules', 'weekdaySchedules']),
        );

        return ApiResponse::success(new CommuteOfferResource($draft), status: 201);
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

    /**
     * GET /v1/commutes/{commute}/price-suggestion — "the fair suggested price".
     *
     * Screen 24 shows it beside the fare slider ("EGP 50 · Suggested EGP 70–95 · EGP 120")
     * while the driver is choosing what to charge. The formula is ERD §23.3's and nothing
     * else: what the journey costs to drive, split by the assumed occupancy.
     *
     * 🔴 Why the platform says a number at all: a commute is shared cost, not a fare, and
     * a driver with no reference either undercharges and quietly subsidises strangers or
     * overcharges and turns their own car into an unlicensed taxi. Only one of those two is
     * their problem to notice.
     *
     * Advice, never a rule — the response carries the bounds so the client can show the
     * slider, and validation still accepts anything between them.
     *
     * Its own endpoint rather than a field on the commute, because it needs the route's
     * length: a field would mean a routing call on every commute fetch, including the
     * list.
     */
    #[ApiErrors(ErrorCode::CommuteIncomplete, ErrorCode::NotFound)]
    public function priceSuggestion(Request $request, string $commute, GeoQueryEngine $geo): JsonResponse
    {
        $offer = $this->owned($request, $commute)->load('locations');

        $origin = $offer->locations->firstWhere('type', CommuteLocationType::Origin);
        $destination = $offer->locations->firstWhere('type', CommuteLocationType::Destination);

        if ($origin === null || $destination === null) {
            /*
             * The route is step one of the wizard and the price is step four, so this
             * should not happen in order — but the endpoint takes an id, and a commute
             * with no route has no length to price.
             */
            throw DomainException::of(ErrorCode::CommuteIncomplete, fields: [
                'missing' => ['route'],
            ]);
        }

        /*
         * Measured here rather than read from `route_distance_meters`, which is null until
         * publish: saving a route deliberately clears it so a draft being edited does not
         * spend a provider call per keystroke. One call at the moment the driver asks
         * what to charge is the right place to pay for it.
         */
        $route = $geo->routeBetween(
            new Coordinate((float) $origin->lat, (float) $origin->lng),
            new Coordinate((float) $destination->lat, (float) $destination->lng),
        );

        $suggestion = FairPriceSuggestion::forDistance($route->distance);

        return ApiResponse::success([
            'suggestedPiastres' => $suggestion->suggestedPiastres,
            'minPiastres' => $suggestion->minPiastres,
            'maxPiastres' => $suggestion->maxPiastres,

            /*
             * Shown as "Full route contribution" on screen 24, with the occupancy it was
             * split by — so the driver can see the reasoning rather than a bare number
             * they have to trust.
             */
            'runCostPiastres' => $suggestion->runCostPiastres,
            'assumedOccupancy' => $suggestion->assumedOccupancy,
            'distanceKm' => round($route->distance->kilometres(), 1),
        ]);
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
