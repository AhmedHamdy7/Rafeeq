<?php

namespace App\Http\Controllers\Api\V1\Rating;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Matching\Support\HardFilters;
use App\Domains\Rating\Actions\ReportReviewAction;
use App\Domains\Rating\Actions\SubmitRatingAction;
use App\Domains\Rating\Enums\ModerationStatus;
use App\Domains\Rating\Enums\RatingDirection;
use App\Domains\Rating\Models\Rating;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Rating\ReportReviewRequest;
use App\Http\Requests\Rating\SubmitRatingRequest;
use App\Http\Resources\PersonSummary;
use App\Http\Resources\PublicReviewResource;
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
     * GET /v1/commutes/{commute}/reviews — the visible reviews of this commute's driver.
     *
     * 🔒 Keyed on the COMMUTE, not on a user id, and that is deliberate rather than awkward. No
     * payload in this API returns a user identifier — `PersonSummary` carries a public first name
     * and nothing resolvable — so a `/users/{id}/reviews` route would have forced us to start
     * handing ids out, undoing a decision made in Phase 6 for the sake of one screen. The client
     * already holds the commute id from search.
     *
     * 🔒 `visible()` is applied explicitly here, which is the half pitfall #26 names. Hidden
     * reviews are not in this list and no parameter can put them in it.
     */
    public function commuteReviews(Request $request, string $commute): JsonResponse
    {
        /*
         * The same eligibility that governs seeing the commute at all. Without it, somebody
         * excluded from a women-only commute could still read its driver's reviews — a smaller
         * leak than seeing the commute, and the same rule should decide both.
         */
        $offer = CommuteOffer::query()
            ->whereKey($commute)
            ->tap(fn ($query) => HardFilters::applyEligibility($query, $request->user()))
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $reviews = Rating::query()
            ->visible()
            ->where('reviewed_user_id', $offer->driver_profile_id)
            ->where('direction', RatingDirection::PassengerToDriver->value)
            // A moderator's takedown is withheld; a complaint is not. See ReportReviewAction.
            ->where('moderation_status', '!=', ModerationStatus::Hidden->value)
            ->with('tags')
            ->latest('visible_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($reviews, PublicReviewResource::collection($reviews->items()));
    }

    /**
     * GET /v1/ratings/about-me — what has been said about the caller, once it is readable.
     *
     * 🔒 Anonymous, in the same shape a stranger sees (`PublicReviewResource`), and the month
     * rather than the day. Showing somebody who gave them two stars is the retaliation vector, not
     * a courtesy — and on a three-seat commute an exact date names the reviewer even when the
     * payload does not. See that class for the full reasoning.
     */
    public function aboutMe(Request $request): JsonResponse
    {
        $reviews = Rating::query()
            ->visible()
            ->where('reviewed_user_id', $request->user()->id)
            ->where('moderation_status', '!=', ModerationStatus::Hidden->value)
            ->with('tags')
            ->latest('visible_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($reviews, PublicReviewResource::collection($reviews->items()));
    }

    /**
     * POST /v1/ratings/{rating}/report — "this review is abusive".
     *
     * 🔴 Flags for a human; takes nothing down. A report that hid the review, or dropped it from
     * the average, would make "report every review under four stars" a mechanical way to launder a
     * record — see `ReportReviewAction`.
     */
    #[ApiErrors(ErrorCode::ReviewAlreadyReported, ErrorCode::NotFound)]
    public function report(ReportReviewRequest $request, string $rating, ReportReviewAction $action): JsonResponse
    {
        $row = Rating::query()->whereKey($rating)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $report = $action->execute($request->user(), $row, $request->string('reason')->value());

        return ApiResponse::success([
            'id' => $report->id,
            'status' => strtoupper($report->status->value),
            // Said plainly, because the expectation matters: the review stays up while a person
            // looks at it, and a client that implied otherwise would be promising a takedown.
            'reviewRemains' => true,
        ], status: 201);
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
