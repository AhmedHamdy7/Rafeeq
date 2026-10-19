<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Domains\Booking\Actions\OfferSeatsToWaitlistMembersAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
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
            ->with('passenger.stats', 'passenger.verifications')
            ->orderBy('waitlist_position')
            // Standard #42: a tiebreak, so a page boundary cannot show or hide the same person.
            ->orderBy('id')
            ->paginate(ApiResponse::perPage($request));

        $passengerIds = collect($members->items())->pluck('passenger_user_id')->unique();

        /*
         * The two card signals, computed once for the page rather than per card. A resource that
         * fetched them per row would be one query per waiting person, and this list is read on a
         * screen where a driver may be looking at twenty of them.
         */
        $previous = $this->passengersWhoHaveRidden($offer, $passengerIds);
        $elsewhere = $this->passengersWithAnotherConfirmedCommute($offer, $passengerIds);

        $cards = [];

        foreach ($members->items() as $member) {
            $cards[] = WaitlistMemberResource::for(
                $member,
                $request->user(),
                isPreviousPassenger: in_array($member->passenger_user_id, $previous, true),
                hasOtherConfirmedCommute: in_array($member->passenger_user_id, $elsewhere, true),
            );
        }

        return ApiResponse::paginated($members, $cards);
    }

    /**
     * POST /v1/driver/commutes/{commute}/waitlist/offers — she offers seats to the people she chose.
     *
     * Her choice decides who may race for the seats, not who gets them: the winner of each day is
     * still settled by server transaction order under that day's row lock, exactly as for an
     * automatic fan-out.
     */
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
     * Which of these passengers already holds a confirmed future seat somewhere else.
     *
     * 🔒 Returns ids only, and the caller turns each into a bare boolean on one card. The driver
     * learns that a woman may already be covered; she learns nothing about which commute, which
     * driver, or which days — see {@see WaitlistMemberResource} on why that line is where it is.
     *
     * @param  Collection<int, string>  $passengerIds
     * @return list<string>
     */
    private function passengersWithAnotherConfirmedCommute(CommuteOffer $offer, $passengerIds): array
    {
        if ($passengerIds->isEmpty()) {
            return [];
        }

        return Booking::query()
            ->whereIn('passenger_user_id', $passengerIds->all())
            ->where('status', BookingStatus::Confirmed->value)
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->where('commute_offer_id', '!=', $offer->getKey())
                ->where('departure_at', '>', now())
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
