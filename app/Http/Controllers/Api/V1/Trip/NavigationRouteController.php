<?php

namespace App\Http\Controllers\Api\V1\Trip;

use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\NavigationRouteResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The driver's map: the run she is about to drive, and what a pending request would do to it
 * (Feature 03 v5 §3, §5).
 *
 * 🔒 Both routes are hers alone, and a miss is a 404 rather than a 403. The payload carries every
 * confirmed passenger's pickup and drop-off — front doors and workplaces — so the ownership check
 * is not a formality: v5 §4 says in terms not to expose other passengers' points to unrelated
 * passengers, and a 403 would additionally confirm that an id exists.
 */
final class NavigationRouteController extends Controller
{
    /**
     * GET /v1/driver/commute-occurrences/{trip}/navigation-route — the authoritative itinerary.
     *
     * Her start, every confirmed passenger's two agreed points in the order she reaches them, and
     * her end: **2 + 2N** stops. The order is computed here and is deliberately NOT the naive
     * `Start → A pickup → A drop-off → B pickup → B drop-off → End` — v5 warns against assuming it,
     * because shared journeys interleave.
     *
     * Rebuilt on read when the bookings have changed since it was last built, which is why this is
     * a GET that can write. The alternative was rebuilding inside the transaction that confirms a
     * seat, with a routing provider's latency held inside a row lock on the seat count — see
     * {@see BuildNavigationItineraryAction}.
     */
    #[ApiErrors(ErrorCode::NotFound, ErrorCode::CommuteRouteInvalid)]
    public function show(Request $request, string $trip, BuildNavigationItineraryAction $action): JsonResponse
    {
        $occurrence = $this->myTrip($request, $trip);

        $stops = $action->forTrip($occurrence);

        // Re-read: the build writes the totals onto the trip, and the instance above predates it.
        $occurrence->refresh();

        return ApiResponse::success([
            ...NavigationRouteResource::confirmed(
                $stops,
                $occurrence->itinerary_polyline,
                $occurrence->itinerary_distance_meters,
                $occurrence->itinerary_duration_seconds,
            ),

            /*
             * Her `Receive Requests During Ride` answer for this morning (Feature 03 v5 §9: the
             * toggle is "accessible without interrupting navigation"), so the navigation screen can
             * render the switch it owns without a second call.
             *
             * 🔒 Published here rather than on the shared trip shape, which reaches passengers
             * through a booking: whether she is willing to be interrupted is an operational setting
             * of hers that no passenger screen has any use for.
             *
             * ⚠️ Nothing acts on it yet — see `PATCH /v1/trips/{trip}/receive-requests`.
             */
            'receivesRequestsDuringRide' => (bool) $occurrence->receives_requests_during_ride,
        ]);
    }

    /**
     * GET /v1/driver/seat-requests/{seatRequest}/route-preview — what accepting her would cost.
     *
     * 🔴 Answers `PREVIEW_NOT_CONFIRMED` and writes nothing. v5 §5 asks for the proposed impact
     * "using existing confirmed stops plus the pending request's two points, clearly marked" — and
     * §2 forbids those points from reaching the official route. Four logical points for one pending
     * passenger where the official itinerary still has two.
     *
     * The day previewed is the request's own day for a trial. A recurring request names no single
     * day, so the next day it could ride is used — the impact is the same shape on each of them,
     * and previewing twenty mornings would be twenty provider calls to answer one question.
     */
    #[ApiErrors(ErrorCode::NotFound, ErrorCode::CommuteRouteInvalid)]
    public function preview(Request $request, string $seatRequest, BuildNavigationItineraryAction $action): JsonResponse
    {
        $pending = SeatRequest::query()
            ->whereKey($seatRequest)
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $request->user()->id)
                ->select('id'))
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $day = $this->dayFor($pending);

        $itinerary = $action->preview(
            $day,
            new Coordinate((float) $pending->requested_pickup_lat, (float) $pending->requested_pickup_lng),
            $pending->requested_pickup_label,
            new Coordinate((float) $pending->requested_dropoff_lat, (float) $pending->requested_dropoff_lng),
            $pending->requested_dropoff_label,
        );

        return ApiResponse::success(NavigationRouteResource::preview($itinerary));
    }

    /**
     * The day a request's impact is previewed against.
     */
    private function dayFor(SeatRequest $pending): ScheduledTrip
    {
        if ($pending->scheduled_trip_id !== null) {
            return ScheduledTrip::query()->whereKey($pending->scheduled_trip_id)->firstOrFail();
        }

        return ScheduledTrip::query()
            ->where('commute_offer_id', $pending->commute_offer_id)
            ->where('departure_at', '>', now())
            ->orderBy('departure_at')
            // Standard #42: a tiebreak, so two days departing at the same minute always preview
            // the same one.
            ->orderBy('id')
            ->first()
            /*
             * No future day to preview against. A refusal rather than an empty itinerary: an
             * itinerary with no day is not "no impact", it is a question that cannot be answered,
             * and showing it as 2 points would read as "she costs you nothing".
             */
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    /**
     * One of the caller's own days. 404 for anybody else's.
     */
    private function myTrip(Request $request, string $tripId): ScheduledTrip
    {
        return ScheduledTrip::query()
            ->whereKey($tripId)
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $request->user()->id)
                ->select('id'))
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
