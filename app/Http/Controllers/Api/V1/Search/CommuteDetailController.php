<?php

namespace App\Http\Controllers\Api\V1\Search;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Matching\Support\HardFilters;
use App\Domains\Matching\Support\PassengerStanding;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\PassengerCommuteResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v1/commutes/{commute}/public — a commute as a PASSENGER may read it (DISCOVER-1, handoff B-1).
 *
 * `GET /commutes/{commute}` is the driver's own commute and needs a verified account, and a
 * passenger is never verified to search or request (D-0012) — so until now nothing a passenger
 * could call returned the suggested points she can pick, the weekdays the commute runs, or its
 * upcoming days with seats left. This does, on the same eligibility rules as the search.
 *
 * 🔴 "Public" means "a passenger's view", not "unauthenticated": the route sits with the search,
 * behind a signed-in, active, complete profile. A scraper's index of every driver's daily movement
 * is exactly what this view is built not to be.
 *
 * 🔒 The route before booking is trimmed by about a kilometre at each end and its ends are named
 * by AREA only (Q2, SAFE-18): enough to see the commute goes her way, not enough to find the
 * driver's front door. The full line comes once she holds a confirmed seat on it.
 */
final class CommuteDetailController extends Controller
{
    /** How many upcoming days to list — about four weeks of a weekday commute. */
    private const int UPCOMING_DAYS = 20;

    #[ApiErrors(ErrorCode::NotFound)]
    public function show(Request $request, string $commute): JsonResponse
    {
        $viewer = $request->user();

        /*
         * The search's own eligibility: blocked, suspended, or otherwise not hers to see answers
         * 404, never 403 — a refusal would confirm the commute exists and what it is. A women-only
         * commute is SHOWN to everybody (C-11); asking for a seat on one is decided when she asks.
         */
        $offer = HardFilters::applyEligibility(CommuteOffer::query()->whereKey($commute), $viewer)
            ->where('status', CommuteOfferStatus::Published->value)
            ->with([
                'locations.place',
                'schedule',
                'rules',
                'vehicle',
                'driverProfile.user.stats',
                'driverProfile.user.verifications',
            ])
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $upcoming = ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->with('commuteOffer')
            ->orderBy('departure_at')
            ->limit(self::UPCOMING_DAYS)
            ->get();

        // A confirmed seat on it, now or ahead, earns the whole route (SAFE-18).
        $booked = Booking::query()
            ->where('passenger_user_id', $viewer->id)
            ->where('status', BookingStatus::Confirmed->value)
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()->where('commute_offer_id', $offer->id)->select('id'))
            ->exists();

        return ApiResponse::success(new PassengerCommuteResource(
            $offer,
            $upcoming->all(),
            PassengerStanding::for($viewer, $upcoming),
            fullRoute: $booked,
        ));
    }
}
