<?php

namespace App\Http\Controllers\Api\V1\Trip;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Actions\AdvanceTripAction;
use App\Domains\Trip\Actions\CompleteTripAction;
use App\Domains\Trip\Actions\StartTripAction;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Trip\AdvanceTripRequest;
use App\Http\Resources\TripSessionResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One day of a commute, while it is happening (Chapter 8).
 *
 * 🔒 Who may see and who may act are two different questions here, and the split is the
 * security design of the whole chapter:
 *
 * - The DRIVER drives the run. Starting, advancing and completing are hers alone, because
 *   they are statements about what the car is doing and only she can make them.
 * - A PASSENGER on the run may READ it. They are in the car, or waiting for it, and the
 *   whole point of the live trip is that they can see where it has got to.
 * - Everybody else gets a 404, including a passenger whose booking was cancelled — a 403
 *   would confirm the trip exists (Bible §6, IDOR).
 */
final class TripController extends Controller
{
    /**
     * GET /v1/trips/{trip} — the run, to its driver or anybody riding on it.
     */
    #[ApiErrors(ErrorCode::TripNotStarted, ErrorCode::NotFound)]
    public function show(Request $request, string $trip): JsonResponse
    {
        $scheduled = $this->visible($request, $trip);

        return ApiResponse::success(new TripSessionResource(
            CompleteTripAction::assertStarted($scheduled->tripSession)
                ->load('scheduledTrip')
        ));
    }

    /**
     * POST /v1/trips/{trip}/start — "Start Today's Commute".
     *
     * Every eligibility check is re-run here rather than trusted from publish time; see
     * the Action for why a licence that expired last week has to stop this run.
     */
    #[ApiErrors(
        ErrorCode::TripAlreadyStarted,
        ErrorCode::TripNotCancellable,
        ErrorCode::TripTooEarlyToStart,
        ErrorCode::DriverNotEligible,
        ErrorCode::LicenceExpired,
        ErrorCode::CommuteVehicleUnavailable,
        ErrorCode::NotFound,
    )]
    public function start(Request $request, string $trip, StartTripAction $action): JsonResponse
    {
        $session = $action->execute($this->driven($request, $trip));

        return ApiResponse::success(
            new TripSessionResource($session->load('scheduledTrip')),
            status: 201,
        );
    }

    /**
     * POST /v1/trips/{trip}/status — moves the run along.
     *
     * One endpoint taking the target state rather than four verbs, because the sequence
     * is a state machine and the interesting logic is which transitions are legal. Four
     * endpoints would be four places to get that wrong.
     */
    #[ApiErrors(
        ErrorCode::TripNotStarted,
        ErrorCode::TripInvalidTransition,
        ErrorCode::NotFound,
    )]
    public function advance(AdvanceTripRequest $request, string $trip, AdvanceTripAction $action): JsonResponse
    {
        $session = AdvanceTripAction::sessionFor($this->driven($request, $trip));

        $updated = $action->execute(
            $session,
            TripSessionStatus::from($request->string('status')->lower()->value()),
        );

        return ApiResponse::success(new TripSessionResource($updated->load('scheduledTrip')));
    }

    /**
     * POST /v1/trips/{trip}/complete — "Complete Trip".
     */
    #[ApiErrors(
        ErrorCode::TripNotStarted,
        ErrorCode::TripInvalidTransition,
        ErrorCode::NotFound,
    )]
    public function complete(Request $request, string $trip, CompleteTripAction $action): JsonResponse
    {
        $session = AdvanceTripAction::sessionFor($this->driven($request, $trip));

        return ApiResponse::success(new TripSessionResource(
            $action->execute($session)->load('scheduledTrip')
        ));
    }

    /**
     * A day of the caller's OWN commute. 404 for anybody else's, drivers included.
     */
    private function driven(Request $request, string $tripId): ScheduledTrip
    {
        return ScheduledTrip::query()
            ->whereKey($tripId)
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $request->user()->id)
                ->select('id'))
            ->with(['commuteOffer.driverProfile', 'tripSession'])
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    /**
     * A day the caller can legitimately look at: theirs to drive, or one they hold a live
     * seat on.
     *
     * A cancelled booking does not count. Somebody who cancelled last night has no reason
     * to watch a car drive around this morning, and "I used to have a seat" is not a
     * reason to be given somebody's live position.
     */
    private function visible(Request $request, string $tripId): ScheduledTrip
    {
        $trip = ScheduledTrip::query()
            ->whereKey($tripId)
            ->with(['commuteOffer.driverProfile', 'tripSession'])
            ->first();

        if ($trip === null) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        if ($trip->commuteOffer->driver_profile_id === $request->user()->id) {
            return $trip;
        }

        $ridesOnIt = Booking::query()
            ->where('scheduled_trip_id', $trip->id)
            ->where('passenger_user_id', $request->user()->id)
            ->whereIn('status', [
                BookingStatus::Confirmed,
                BookingStatus::Pending,
                // Completed too: a passenger may still read the run they were on, which
                // is what the dispute window needs them to be able to do.
                BookingStatus::Completed,
            ])
            ->exists();

        return $ridesOnIt ? $trip : throw DomainException::of(ErrorCode::NotFound);
    }
}
