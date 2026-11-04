<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Domains\Booking\Actions\OfferSeatsToWaitlistMembersAction;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\DriverSeatOffers;
use App\Domains\Booking\Support\OtherConfirmedCommutes;
use App\Domains\Booking\Support\WaitlistEligibility;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Booking\ListDriverSeatOffersRequest;
use App\Http\Requests\Booking\ListDriverWaitlistRequest;
use App\Http\Requests\Booking\OfferSeatsToWaitlistRequest;
use App\Http\Resources\SeatOfferResource;
use App\Http\Resources\WaitlistMemberResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The driver's waiting list, and the one screen where she hands out seats herself (Feature 03 v5).
 *
 * 🔴 Why a driver-chooses endpoint exists at all, when a freed seat normally goes out to everybody
 * automatically. v5 makes this an explicit exception: *"Driver manually Resume. Exception to normal
 * automatic seat-open fanout: driver chooses individual, selected subset, or all waiting-list
 * members to offer seats to; may prioritize previous/recurring passengers."* Resuming a paused
 * commute means refilling a whole car for weeks of mornings, not patching one hole — see
 * {@see OfferSeatsToWaitlistMembersAction} for the full argument.
 *
 * 🔒 Every route here is scoped to a commute the caller owns, and a miss is a 404 rather than a 403
 * (Bible §6, IDOR): a 403 would confirm the commute exists, and a waiting list is a list of women
 * who want to be in a particular car at a particular time.
 */
final class WaitlistController extends Controller
{
    private const int MAX_DAY_CHIPS = 14;

    private const int UPCOMING_DAYS_SCANNED = 120;

    /**
     * GET /v1/driver/commutes/{commute}/waitlist — who is waiting, with what she needs to choose.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function index(Request $request, string $commute): JsonResponse
    {
        $offer = $this->myCommute($request, $commute);

        $members = SeatRequest::query()
            ->where('commute_offer_id', $offer->getKey())
            ->where('status', SeatRequestStatus::Waitlisted->value)
            // PersonSummary needs both: a driver deciding who rides reads the passenger's rating
            // and badges, not just her name.
            ->with('passenger.stats', 'passenger.verifications', 'scheduledTrip', 'commuteOffer.locations')
            ->orderBy('waitlist_position')
            // Standard #42: a tiebreak, so a page boundary cannot show or hide the same person.
            ->orderBy('id')
            ->paginate(ApiResponse::perPage($request));

        $cards = $this->cards($request, collect($members->items()));

        return ApiResponse::paginated($members, $cards);
    }

    /**
     * POST /v1/driver/commutes/{commute}/waitlist/offers — she offers seats to the people she chose.
     *
     * Her choice decides who may race for the seats, not who gets them: the winner of each day is
     * still settled by server transaction order under that day's row lock, exactly as for an
     * automatic fan-out.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function all(ListDriverWaitlistRequest $request): JsonResponse
    {
        $commutes = CommuteOffer::query()
            ->where('driver_profile_id', $request->user()->id)
            ->select('id');

        $waiting = SeatRequest::query()
            ->whereIn('commute_offer_id', $commutes)
            ->where('status', SeatRequestStatus::Waitlisted->value)
            ->get();

        $days = $this->upcomingDays($commutes, $waiting);
        $members = SeatRequest::query()
            ->whereIn('id', $this->memberIds($request, $commutes, $waiting))
            ->with('passenger.stats', 'passenger.verifications', 'scheduledTrip', 'commuteOffer.locations')
            ->orderBy('commute_offer_id')
            ->orderBy('waitlist_position')
            ->orderBy('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($members, $this->cards($request, collect($members->items())), [
            'waitingCount' => $waiting->unique('passenger_user_id')->count(),
            'days' => $days,
        ]);
    }

    public function offers(ListDriverSeatOffersRequest $request): JsonResponse
    {
        return ApiResponse::success(DriverSeatOffers::rounds($request->user()->id, $request->statuses()));
    }

    #[ApiErrors(ErrorCode::NotFound, ErrorCode::CommuteNotEditable)]
    public function offer(
        OfferSeatsToWaitlistRequest $request,
        string $commute,
        OfferSeatsToWaitlistMembersAction $action,
    ): JsonResponse {
        $offer = $this->myCommute($request, $commute);

        $waiting = SeatRequest::query()
            ->where('commute_offer_id', $offer->getKey())
            ->where('status', SeatRequestStatus::Waitlisted->value)
            /*
             * 🔒 The chosen ids are filtered against HER OWN commute's waiting list rather than
             * trusted. Without this, a driver could pass a seat request id belonging to somebody
             * else's commute and create offers against a journey that is not hers.
             *
             * Silently, and deliberately: an id that is not on this list simply does not appear in
             * the result. Telling her "that id is not yours" would answer a question about somebody
             * else's commute.
             */
            ->when(
                ! $request->boolean('all'),
                fn ($query) => $query->whereIn('id', (array) $request->validated('seatRequestIds')),
            )
            ->with('passenger')
            ->orderBy('waitlist_position')
            ->orderBy('id')
            ->get();

        $created = $action->execute($offer, $waiting);

        return ApiResponse::success([
            'membersOffered' => $waiting->count(),
            // One member can be offered several days: a recurring waiter resumed mid-month is
            // eligible for every remaining weekday she committed to.
            'offersCreated' => $created,
        ]);
    }

    /**
     * DELETE /v1/driver/seat-offers/{offer} — she takes an unaccepted offer back.
     *
     * v5: *"Driver may withdraw only unaccepted offers."* Once accepted it is a booking, and taking
     * that back is a driver cancellation with its fees and reliability impact — not an undo — so
     * anything already closed is refused here.
     */
    #[ApiErrors(ErrorCode::NotFound, ErrorCode::SeatOfferClosed)]
    public function withdrawOffer(
        Request $request,
        string $offer,
        OfferSeatsToWaitlistMembersAction $action,
    ): JsonResponse {
        $target = SeatOffer::query()
            ->whereKey($offer)
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->whereIn('commute_offer_id', CommuteOffer::query()
                    ->where('driver_profile_id', $request->user()->id)
                    ->select('id'))
                ->select('id'))
            ->with('scheduledTrip')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        return ApiResponse::success(new SeatOfferResource($action->withdraw($target)));
    }

    /**
     * Which of these passengers has held a seat in this car before — the **Previous Passenger**
     * label.
     *
     * A booking only exists because a driver approved a request, so its mere existence is the
     * proof: no status filter, because a seat the platform later cancelled when the commute was
     * paused was still a seat she held, and she is exactly who this label is for.
     *
     * @param  Collection<int, string>  $passengerIds
     * @return list<string>
     */
    private function cards(Request $request, Collection $members): array
    {
        $passengerIds = $members->pluck('passenger_user_id')->unique();
        $elsewhere = OtherConfirmedCommutes::forPassengers($passengerIds);
        $offers = DriverSeatOffers::latestForRequests($members->pluck('id'));
        $previous = [];
        $cards = [];

        foreach ($members as $member) {
            $previous[$member->commute_offer_id] ??= $this->passengersWhoHaveRidden($member->commuteOffer, $passengerIds);

            $cards[] = WaitlistMemberResource::for(
                $member,
                $request->user(),
                isPreviousPassenger: in_array($member->passenger_user_id, $previous[$member->commute_offer_id], true),
                hasOtherConfirmedCommute: OtherConfirmedCommutes::elsewhere($elsewhere, $member->passenger_user_id, $member->commute_offer_id),
                offer: $offers[$member->getKey()] ?? null,
            );
        }

        return $cards;
    }

    private function memberIds(ListDriverWaitlistRequest $request, mixed $commutes, Collection $waiting): Collection
    {
        if (! $request->filled('tripId')) {
            return $waiting->pluck('id');
        }

        $trip = ScheduledTrip::query()
            ->whereKey($request->validated('tripId'))
            ->whereIn('commute_offer_id', $commutes)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        return $waiting
            ->filter(fn (SeatRequest $member): bool => WaitlistEligibility::isForDay($member, $trip))
            ->pluck('id');
    }

    private function upcomingDays(mixed $commutes, Collection $waiting): array
    {
        if ($waiting->isEmpty()) {
            return [];
        }

        $trips = ScheduledTrip::query()
            ->whereIn('commute_offer_id', $commutes)
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->orderBy('departure_at')
            ->orderBy('id')
            ->limit(self::UPCOMING_DAYS_SCANNED)
            ->get();

        $days = [];

        foreach ($trips as $trip) {
            if (count($days) === self::MAX_DAY_CHIPS) {
                break;
            }

            if (! $waiting->contains(fn (SeatRequest $member): bool => WaitlistEligibility::isForDay($member, $trip))) {
                continue;
            }

            $days[] = [
                'date' => $trip->trip_date->toDateString(),
                'tripId' => $trip->id,
                'commuteId' => $trip->commute_offer_id,
                'departureLocal' => $trip->departure_local?->format('Y-m-d H:i:s'),
                'isFull' => $trip->seats_taken >= $trip->seats_total,
                'waitingCount' => $waiting
                    ->filter(fn (SeatRequest $member): bool => WaitlistEligibility::isWaitingFor($member, $trip))
                    ->unique('passenger_user_id')
                    ->count(),
            ];
        }

        return $days;
    }

    private function passengersWhoHaveRidden(CommuteOffer $offer, $passengerIds): array
    {
        if ($passengerIds->isEmpty()) {
            return [];
        }

        return Booking::query()
            ->whereIn('passenger_user_id', $passengerIds->all())
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->where('commute_offer_id', $offer->getKey())
                ->select('id'))
            ->distinct()
            ->pluck('passenger_user_id')
            ->all();
    }

    /**
     * One of the caller's own commutes. 404 for anybody else's.
     */
    private function myCommute(Request $request, string $commuteId): CommuteOffer
    {
        return CommuteOffer::query()
            ->whereKey($commuteId)
            ->where('driver_profile_id', $request->user()->id)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
