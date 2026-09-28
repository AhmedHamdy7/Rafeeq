<?php

namespace App\Http\Controllers\Api\V1\Trip;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Actions\AdvanceTripAction;
use App\Domains\Trip\Actions\WaitTimerAction;
use App\Domains\Trip\Models\TripWaitTimer;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Trip\StartWaitTimerRequest;
use App\Http\Resources\WaitTimerResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Waiting at a gate for somebody who is not there yet (screen 41).
 *
 * 🔴 There is no endpoint here that ends the wait, and that is the design. "She's here"
 * is a check-in and "Mark no-show & depart" is a no-show — both already exist, and both
 * close the timer as part of the same call. A separate "stop the timer" route would let
 * the two records disagree, and the disagreement would always land the same way: a timer
 * left running on a passenger who was marked present reads, months later, as somebody the
 * driver abandoned at a gate.
 *
 * 🔒 The driver's endpoints only. A passenger watching a countdown of her own lateness
 * would be told something the product has no reason to tell her, and could not act on it
 * anyway — the one useful thing, that the car is waiting, belongs in a notification
 * (Phase 12).
 */
final class WaitTimerController extends Controller
{
    /**
     * GET /v1/trips/{trip}/wait-timers — every wait on this run, newest first.
     *
     * The finished ones too. A run where the driver waited nine minutes for one person and
     * left another after ninety seconds is a run somebody may ask about, and the answer is
     * this list.
     */
    #[ApiErrors(ErrorCode::TripNotStarted, ErrorCode::NotFound)]
    public function index(Request $request, string $trip): JsonResponse
    {
        $session = AdvanceTripAction::sessionFor($this->driven($request, $trip));

        $timers = TripWaitTimer::query()
            ->where('trip_session_id', $session->id)
            ->with('booking.passenger.stats', 'booking.passenger.verifications')
            ->latest('started_at')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(WaitTimerResource::collection($timers));
    }

    /**
     * POST /v1/trips/{trip}/wait-timers — "Start the 5-minute wait timer".
     */
    #[ApiErrors(
        ErrorCode::TripNotStarted,
        ErrorCode::WaitTimerAlreadyRunning,
        ErrorCode::AttendanceNotConfirmable,
        ErrorCode::NotFound,
    )]
    public function store(StartWaitTimerRequest $request, string $trip, WaitTimerAction $action): JsonResponse
    {
        $scheduled = $this->driven($request, $trip);
        $session = AdvanceTripAction::sessionFor($scheduled);

        $timer = $action->start(
            $session,
            $this->bookingOn($scheduled, $request->string('bookingId')->value()),
        );

        return ApiResponse::success(new WaitTimerResource($timer), status: 201);
    }

    /**
     * POST /v1/trips/{trip}/wait-timers/{timer}/extend — the "+2 min" button.
     */
    #[ApiErrors(
        ErrorCode::TripNotStarted,
        ErrorCode::WaitTimerNotRunning,
        ErrorCode::NotFound,
    )]
    public function extend(Request $request, string $trip, string $timer, WaitTimerAction $action): JsonResponse
    {
        $session = AdvanceTripAction::sessionFor($this->driven($request, $trip));

        $running = TripWaitTimer::query()
            ->whereKey($timer)
            ->where('trip_session_id', $session->id)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        return ApiResponse::success(new WaitTimerResource($action->extend($running)));
    }

    /**
     * A seat on THIS run. 404 for a booking from another day, so an id cannot be probed by
     * pointing it at a run the caller does drive.
     */
    private function bookingOn(ScheduledTrip $trip, string $bookingId): Booking
    {
        return Booking::query()
            ->whereKey($bookingId)
            ->where('scheduled_trip_id', $trip->id)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
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
            ->with('tripSession')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
