<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Domains\Booking\Actions\AcceptSeatOfferAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SwitchReason;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Booking\AcceptSeatOfferRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\SeatOfferResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SeatOfferController extends Controller
{
    /**
     * GET /v1/seat-offers — seats she has been offered while waiting.
     *
     * 🔒 Scoped to her own waiting requests. There is no endpoint at any access level that shows
     * who else was offered the same seat: a waiting list is a set of people who all want the same
     * commute, and telling each of them who they are racing would turn a seat into a social
     * problem.
     */
    public function index(Request $request): JsonResponse
    {
        $offers = SeatOffer::query()
            ->whereHas('seatRequest', fn ($query) => $query
                ->where('passenger_user_id', $request->user()->id))
            ->with('scheduledTrip')
            // Live ones first, then the history — newest of each, because the only one she can act
            // on is an open one and it should never be below a week of closed ones.
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->latest('created_at')
            ->paginate();

        return ApiResponse::paginated($offers, SeatOfferResource::collection($offers->items()));
    }

    /**
     * POST /v1/seat-offers/{offer}/accept — take the seat.
     *
     * 🔴 First acceptance wins, decided by **server transaction order** and never by a timestamp
     * the client sent. v3 is explicit that accepting "first accept" does not authorise
     * first-device-tap priority: who reaches us first depends on her signal and her distance from
     * the data centre, which is not a fair basis for giving away a seat.
     *
     * A loser gets `409 SEAT_OFFER_CLOSED` with `error.fields.status` saying which — `taken_by_other`
     * means somebody was quicker. Show that, not an error.
     */
    #[ApiErrors(ErrorCode::NotFound, ErrorCode::SeatOfferClosed, ErrorCode::AlreadyBooked)]
    public function accept(AcceptSeatOfferRequest $request, string $offer, AcceptSeatOfferAction $action): JsonResponse
    {
        $target = SeatOffer::query()
            ->whereKey($offer)
            ->whereHas('seatRequest', fn ($query) => $query
                ->where('passenger_user_id', $request->user()->id))
            ->first();

        /*
         * 404 for somebody else's offer, never 403: a 403 would confirm the id exists, which turns
         * this into an enumeration oracle for who is waiting on which commute.
         */
        if ($target === null) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        /*
         * 🔒 The booking she is leaving must be HER OWN, found through her own id rather than
         * trusted from the payload. Without that, a passenger could pass somebody else's booking
         * id and have their seat released on her behalf.
         *
         * A 404-shaped miss for the same reason as the offer: a 403 would confirm the id exists.
         */
        $switchFrom = null;

        if ($request->filled('switchFromBookingId')) {
            $switchFrom = Booking::query()
                ->whereKey($request->validated('switchFromBookingId'))
                ->where('passenger_user_id', $request->user()->id)
                ->where('status', BookingStatus::Confirmed->value)
                ->with('scheduledTrip', 'seatRequest')
                ->first();

            if ($switchFrom === null) {
                throw DomainException::of(ErrorCode::NotFound);
            }
        }

        $booking = $action->execute(
            $target,
            $switchFrom,
            SwitchReason::from((string) ($request->validated('switchReason') ?? SwitchReason::Voluntary->value)),
            (bool) ($request->validated('joinOldWaitlist') ?? false),
        );

        return ApiResponse::success(new BookingResource($booking));
    }
}
