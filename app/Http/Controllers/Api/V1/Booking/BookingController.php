<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Domains\Booking\Actions\CancelBookingAction;
use App\Domains\Booking\Models\Booking;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
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
     * A booking the caller is a party to — as its passenger or as its driver.
     */
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
