<?php

namespace App\Http\Controllers\Api\V1\Rating;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Rating\Actions\SubmitRatingAction;
use App\Domains\Rating\Models\Rating;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Rating\SubmitRatingRequest;
use App\Http\Resources\PersonSummary;
use App\Http\Resources\RatingResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rating the other person on a journey (Chapter 9, screen 37).
 *
 * 🔴 Everything here is scoped to a booking the caller was actually on. There is no endpoint in
 * this controller that reads a rating somebody else wrote — that is a different shape with a
 * different filter, and keeping it out of this class is deliberate: pitfall #26 is broken by
 * convenience, not by malice.
 */
final class RatingController extends Controller
{
    /**
     * GET /v1/ratings/pending — journeys still waiting for the caller's rating.
     *
     * What screen 37 is launched from. Returns the bookings this person may rate and has not, with
     * the other party's public summary so the screen can say who it is about, and the deadline so
     * it can say how long is left.
     *
     * 🔒 It says nothing about whether the OTHER person has rated. That is exactly the fact
     * double-blind exists to withhold, and a "they are waiting for you" badge would leak it.
     */
    public function pending(Request $request, SubmitRatingAction $ratings): JsonResponse
    {
        $user = $request->user();

        $bookings = Booking::query()
            ->where('status', BookingStatus::Completed)
            ->where(fn ($query) => $query
                ->where('passenger_user_id', $user->id)
                ->orWhere('driver_profile_id', $user->id))
            // Nothing they have already rated.
            ->whereDoesntHave('ratings', fn ($query) => $query->where('reviewer_user_id', $user->id))
            ->with([
                'scheduledTrip.commuteOffer',
                'passenger.stats', 'passenger.verifications',
                'driverProfile.user.stats', 'driverProfile.user.verifications',
            ])
            ->get();

        $rows = [];

        foreach ($bookings as $booking) {
            $deadline = $ratings->deadlineFor($booking);

            // The window has gone. Filtered here rather than in SQL because the deadline is
            // derived from a setting that can change, and a query would freeze today's value into
            // an index-friendly comparison that is wrong tomorrow.
            if ($deadline->isPast()) {
                continue;
            }

            $isPassenger = $booking->passenger_user_id === $user->id;

            $other = $isPassenger ? $booking->driverProfile?->user : $booking->passenger;

            if ($other === null) {
                continue;
            }

            $rows[] = [
                'bookingId' => $booking->id,
                'tripDate' => $booking->scheduledTrip->trip_date,
                // Who it is about, in the shape every other screen uses (5.1).
                'person' => PersonSummary::for($other, $user, asDriver: $isPassenger),
                'direction' => $isPassenger ? 'passenger_to_driver' : 'driver_to_passenger',
                'rateableUntil' => $deadline->toIso8601String(),
            ];
        }

        return ApiResponse::success($rows);
    }

    /**
     * POST /v1/bookings/{booking}/rating — rate the other person.
     *
     * 🔴 The response is the caller's OWN rating, which is the one thing they are allowed to read
     * before the reveal. `isVisible` says whether anybody else can see it yet, and says nothing
     * about whether they have rated.
     */
    #[ApiErrors(
        ErrorCode::TripNotRateable,
        ErrorCode::RatingWindowClosed,
        ErrorCode::RatingAlreadySubmitted,
        ErrorCode::NotFound,
    )]
    public function store(SubmitRatingRequest $request, string $booking, SubmitRatingAction $action): JsonResponse
    {
        $rating = $action->submit(
            $request->user(),
            $this->bookingOnTheJourney($request, $booking),
            $request->rating(),
        );

        return ApiResponse::success(new RatingResource($rating->load('tags')), status: 201);
    }

    /**
     * PATCH /v1/ratings/{rating} — fix what you wrote.
     *
     * 🔒 Refused the moment the rating becomes visible, whatever the edit clock says. Without that,
     * "rate five stars, wait for the reveal, read hers, revise mine to one" would be two
     * legitimate API calls — see `SubmitRatingAction`.
     */
    #[ApiErrors(ErrorCode::RatingNotEditable, ErrorCode::NotFound)]
    public function update(SubmitRatingRequest $request, string $rating, SubmitRatingAction $action): JsonResponse
    {
        $own = Rating::query()->whereKey($rating)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        return ApiResponse::success(new RatingResource(
            $action->update($request->user(), $own, $request->rating())->load('tags')
        ));
    }

    /**
     * GET /v1/ratings/mine — what the caller has written, newest first.
     *
     * Their own, so no visibility filter applies. A person may always read what they said.
     */
    public function mine(Request $request): JsonResponse
    {
        $ratings = Rating::query()
            ->where('reviewer_user_id', $request->user()->id)
            ->with('tags')
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($ratings, RatingResource::collection($ratings->items()));
    }

    /**
     * 🔒 A booking the caller was on, or a 404.
     *
     * 404 rather than 403, and for a booking belonging to two strangers as much as for one that
     * does not exist: whether a particular journey happened is not something somebody may learn by
     * asking about its id.
     */
    private function bookingOnTheJourney(Request $request, string $bookingId): Booking
    {
        $user = $request->user();

        return Booking::query()
            ->whereKey($bookingId)
            ->where(fn ($query) => $query
                ->where('passenger_user_id', $user->id)
                ->orWhere('driver_profile_id', $user->id))
            ->with('scheduledTrip')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
