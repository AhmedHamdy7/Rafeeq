<?php

namespace App\Http\Controllers\Api\V1\Trip;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\DuringRideRequests;
use App\Domains\Commute\Actions\CancelTripDayAction;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Actions\AdvanceTripAction;
use App\Domains\Trip\Actions\AutoFinishTripsAction;
use App\Domains\Trip\Actions\CancelActiveTripAction;
use App\Domains\Trip\Actions\CompleteTripAction;
use App\Domains\Trip\Actions\StartTripAction;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Support\PickupReachability;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Trip\AdvanceTripRequest;
use App\Http\Requests\Trip\CancelActiveTripRequest;
use App\Http\Requests\Trip\CancelTripDayRequest;
use App\Http\Requests\Trip\SetReceiveRequestsRequest;
use App\Http\Resources\ScheduledTripResource;
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
     * POST /v1/trips/{trip}/still-driving — "I am still on the road" (Feature 03 v5).
     *
     * Pushes back the moment the run would otherwise be closed for her, by a quarter of an hour,
     * as many times as the road needs. There is deliberately no limit: a woman stuck in Cairo
     * traffic is not more suspicious the fourth time she says so, and a cap would have to invent an
     * answer for what happens then.
     *
     * ⚠️ It is a convenience, not a safeguard. A run is only ever closed automatically when nobody
     * is aboard and nobody is still awaiting a decision — so a driver who never taps this still
     * cannot have a journey with people in it ended underneath her.
     */
    #[ApiErrors(ErrorCode::TripNotStarted, ErrorCode::NotFound)]
    public function stillDriving(Request $request, string $trip, AutoFinishTripsAction $action): JsonResponse
    {
        $session = AdvanceTripAction::sessionFor($this->driven($request, $trip));

        if (! $session->current_status->isUnderway()) {
            // Nothing to extend on a run that has not set off or has already closed.
            throw DomainException::of(ErrorCode::TripNotStarted, fields: [
                'tripStatus' => [strtoupper($session->current_status->value)],
            ]);
        }

        return ApiResponse::success(new TripSessionResource(
            $action->stillDriving($session)->load('scheduledTrip')
        ));
    }

    /**
     * POST /v1/trips/{trip}/cancel — "Cancel today" (screen 23): one day off, the commute stands.
     *
     * Every passenger on the day is told and their booking ends as cancelled by the driver; see
     * the Action for what else closes with it.
     */
    #[ApiErrors(ErrorCode::TripNotCancellable, ErrorCode::NotFound)]
    public function cancel(CancelTripDayRequest $request, string $trip, CancelTripDayAction $action): JsonResponse
    {
        $cancelled = $action->execute($this->driven($request, $trip), $request->user(), $request->validated('reason'));

        return ApiResponse::success(new ScheduledTripResource($cancelled));
    }

    /**
     * POST /v1/trips/{trip}/cancel-active — "Cancel Active Trip" (Feature 03 v5 §123–125): the
     * driver stops a run that has already set off.
     *
     * 🔴 **A separate endpoint from "cancel today" on purpose.** v5 opens the section with *"Pause
     * Commute cannot cancel an already-started trip"*, and the two acts differ in everything that
     * follows: this one carries the heaviest reliability weight in the ledger, ends the journeys of
     * people who are in the car, and may be appealed. One endpoint that meant "call off tomorrow"
     * or "stop the car" depending on whether the run had departed is how a driver cancels a
     * morning she meant to cancel the next day.
     *
     * The response says how many passengers were aboard and how many were still waiting, because
     * those are two different things for her to deal with and v5 §127 asks her to handle them
     * safely. It does not book anybody anything — each passenger is pointed at
     * `GET /v1/bookings/{booking}/alternatives`, which suggests and never commits.
     */
    #[ApiErrors(ErrorCode::TripNotStarted, ErrorCode::TripNotCancellable, ErrorCode::NotFound)]
    public function cancelActive(
        CancelActiveTripRequest $request,
        string $trip,
        CancelActiveTripAction $action,
    ): JsonResponse {
        $session = AdvanceTripAction::sessionFor($this->driven($request, $trip));

        $outcome = $action->execute($session, $request->user(), $request->validated('reason'));

        return ApiResponse::success(
            new ScheduledTripResource($outcome['trip']),
            meta: [
                // Passengers who were in the car: their bookings are `ENDED_EARLY` and the part
                // they rode still counts.
                'endedEarly' => $outcome['endedEarly'],
                // Passengers never collected: `CANCELLED_BY_DRIVER`.
                'cancelled' => $outcome['cancelled'],
            ],
        );
    }

    /**
     * A day of the caller's OWN commute. 404 for anybody else's, drivers included.
     */
    /**
     * PATCH /v1/trips/{trip}/receive-requests — `Receive Requests During Ride` for one morning
     * (Feature 03 v5 §8, §9).
     *
     * 🔴 This is the first gate on a request made to a car that is already moving, and it is read
     * before any geometry runs: honouring a "no" costs nothing, while answering "could she still
     * reach you" costs a route calculation. See
     * {@see DuringRideRequests} for the gate and
     * {@see PickupReachability} for what it guards.
     *
     * The switch shipped before the feature it governs, and the direction is why: the default is
     * **yes**, so every driver's stored state already matched the behaviour she had. The only
     * answer that could have been broken was "no" — and a "no" first asked for on the day the
     * feature launched would have meant the first mid-journey request reaching a driver who had
     * already refused them.
     *
     * **Per occurrence, as v5 specifies.** Whether she can take another passenger depends on this
     * morning — who is aboard, how late she is, where she has got to — so a commute-wide switch
     * would make her answer for a quiet Thursday on the strength of a bad Tuesday.
     *
     * ⚠️ v5 proposes this at `/driver/commutes/{id}/receive-requests`, where `{id}` is an
     * occurrence; occurrences are `/trips/{trip}` throughout this API, as `start`, `complete` and
     * `still-driving` already are.
     */
    #[ApiErrors(ErrorCode::NotFound, ErrorCode::TripNotStarted)]
    public function receiveRequests(SetReceiveRequestsRequest $request, string $trip): JsonResponse
    {
        $day = $this->driven($request, $trip);

        /*
         * Refused on a morning that is over. A toggle on a finished or cancelled day changes
         * nothing, and answering OK to it would tell a client it had been applied.
         */
        if ($day->status->isTerminal()) {
            throw DomainException::of(ErrorCode::TripNotStarted, fields: [
                'tripStatus' => [strtoupper($day->status->value)],
            ]);
        }

        // `forceFill`: a fillable flag on this model would let a request that writes a seat count
        // also write the driver's answer about being interrupted.
        $day->forceFill([
            'receives_requests_during_ride' => (bool) $request->validated('receiveRequests'),
        ])->save();

        /*
         * 🔒 Its own small answer rather than the trip shape, which does not carry this flag on
         * purpose: that shape is embedded in a booking and reaches passengers, and her willingness
         * to be interrupted mid-journey is hers. The driver reads it back on the navigation route,
         * which is where the toggle lives.
         */
        return ApiResponse::success([
            'tripId' => (string) $day->getKey(),
            'receivesRequestsDuringRide' => (bool) $day->receives_requests_during_ride,
        ]);
    }

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
