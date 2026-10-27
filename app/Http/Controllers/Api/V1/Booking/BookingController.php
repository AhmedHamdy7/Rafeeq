<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Domains\Booking\Actions\CancelBookingAction;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Actions\AdvanceTripAction;
use App\Domains\Trip\Actions\EndBookingEarlyAction;
use App\Domains\Trip\Actions\ExitForDriverDelayAction;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\BookingResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🔒 Chapter 6's security section, enforced by scoping every lookup:
 * "passengers cannot access others' bookings" and "drivers see only their own".
 *
 * There is no unscoped route here. A booking is reachable by its owner as the
 * passenger, or by the driver who approved it, and by nobody else — 404 either way,
 * because a 403 would confirm the id exists.
 */
final class BookingController extends Controller
{
    /**
     * GET /v1/my-bookings — the caller's own seats, newest first.
     *
     * Paged. A recurring member accrues a booking per travelling day, so this list is
     * hundreds of rows within a term and thousands within a year — and the whole of it
     * arriving in one response on a phone is the failure that only shows up on the
     * people who have used the product longest.
     */
    public function mine(Request $request): JsonResponse
    {
        $bookings = Booking::query()
            ->where('passenger_user_id', $request->user()->id)
            ->with('scheduledTrip')
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($bookings, BookingResource::collection($bookings->items()));
    }

    /**
     * GET /v1/driver/bookings — the seats the caller has approved on their commutes.
     *
     * The largest list in the API: every seat on every day of every commute they run.
     */
    public function forDriver(Request $request): JsonResponse
    {
        $bookings = Booking::query()
            ->where('driver_profile_id', $request->user()->id)
            ->with('scheduledTrip')
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($bookings, BookingResource::collection($bookings->items()));
    }

    /**
     * GET /v1/bookings/{booking} — visible to its passenger or its driver.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function show(Request $request, string $booking): JsonResponse
    {
        return ApiResponse::success(
            new BookingResource($this->visible($request, $booking)->load('scheduledTrip'))
        );
    }

    /**
     * PATCH /v1/bookings/{booking}/cancel — from whichever side is asking.
     *
     * The seat is released under the same lock that takes it. No fee is charged:
     * the cancellation policy is still an open question, and a guessed fee would
     * take money from a real person on the strength of an assumption.
     */
    #[ApiErrors(ErrorCode::BookingNotCancellable, ErrorCode::NotFound)]
    public function cancel(Request $request, string $booking, CancelBookingAction $action): JsonResponse
    {
        $target = $this->visible($request, $booking);
        $reason = $request->input('reason');

        // Which side is cancelling is read from who the caller IS, never from the
        // request: a passenger must not be able to record their own cancellation as
        // the driver's, which would move the blame for a missed ride.
        $cancelled = $target->passenger_user_id === $request->user()->id
            ? $action->byPassenger($target, $reason)
            : $action->byDriver($target, $request->user()->id, $reason);

        return ApiResponse::success(new BookingResource($cancelled));
    }

    /**
     * POST /v1/bookings/{booking}/end-early — the passenger getting out before her drop-off
     * (Feature 03 v5's safety exit).
     *
     * 🔴 **Hers alone, and it asks for nothing.** No reason is required, no confirmation from the
     * driver, no window. A woman who wants out of a car is not somebody to put a form in front of,
     * and a flow needing the driver's agreement would need it from the person she may be getting
     * away from. The `reason` field exists and is optional; support reads it, the driver never does.
     *
     * 🔒 Her access to the car's live position ends with it. Mostly that is structural — the
     * position is only readable on a pending or confirmed booking — and the share link she may have
     * sent a friend is revoked explicitly, because that one hangs off the run rather than her seat.
     *
     * Before she has been picked up this is the wrong call: cancelling is, and v5 says so. The
     * Action refuses with `ATTENDANCE_NOT_CONFIRMABLE` naming where she actually is.
     */
    #[ApiErrors(
        ErrorCode::BookingNotCancellable,
        ErrorCode::AttendanceNotConfirmable,
        ErrorCode::TripNotStarted,
        ErrorCode::NotFound,
    )]
    public function endEarly(Request $request, string $booking, EndBookingEarlyAction $action): JsonResponse
    {
        $target = $this->visible($request, $booking);

        /*
         * 🔒 Only the passenger. A driver wanting somebody out of her car is a different act with
         * different consequences — v5 gives her Cancel Active Trip, with a reason, a safe stop and
         * support — and letting it in here would record her decision as the passenger's own exit.
         */
        if ($target->passenger_user_id !== $request->user()->id) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        $session = AdvanceTripAction::sessionFor(
            ScheduledTrip::query()->whereKey($target->scheduled_trip_id)->firstOrFail()
        );

        return ApiResponse::success(new BookingResource(
            $action->execute($session, $target, $request->input('reason'))
        ));
    }

    /**
     * A booking the caller is a party to — as its passenger or as its driver.
     */
    /**
     * POST /v1/bookings/{booking}/exit-without-fee — she gives up on a journey the driver never
     * came for (Feature 03 v5 §241).
     *
     * 🔴 **The feature is what it does not cost her.** She could always cancel before pickup; what
     * it cost was a cancellation fee, a line in the cancellation history a future driver reads, and
     * a mark on her reliability record — for a morning somebody else ruined. All three move to the
     * driver, and the booking gets its own status so none of it reads as her decision.
     *
     * 🔒 **Entitlement is decided by the server from facts it owns** — the run has not set off after
     * its departure, or the car has already reached a stop beyond hers. A `delayed: true` in a
     * request body would be a free cancellation for the asking, paid for by a driver who was on
     * time. Asking too early answers `409` with `error.fields.driverDelay: ["NOT_DELAYED"]`;
     * `driverDelay` on the booking tells the client when to show the button at all.
     */
    #[ApiErrors(ErrorCode::BookingNotCancellable, ErrorCode::NotFound)]
    public function exitWithoutFee(Request $request, string $booking, ExitForDriverDelayAction $action): JsonResponse
    {
        $target = $this->visible($request, $booking);

        // 🔒 Hers alone. A driver has her own paths for a run she cannot make, and routing hers
        // through here would record her failure as the passenger's exit.
        if ($target->passenger_user_id !== $request->user()->id) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return ApiResponse::success(new BookingResource(
            $action->execute($target, $request->input('reason'))
        ));
    }

    private function visible(Request $request, string $bookingId): Booking
    {
        $userId = $request->user()->id;

        return Booking::query()
            ->whereKey($bookingId)
            ->where(fn ($query) => $query
                ->where('passenger_user_id', $userId)
                ->orWhere('driver_profile_id', $userId))
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
