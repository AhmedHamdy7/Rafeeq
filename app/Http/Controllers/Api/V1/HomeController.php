<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PickupPointRequestStatus;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use App\Domains\Matching\Models\SavedSearch;
use App\Domains\Verification\Support\VerificationCentre;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\JourneyCard;
use App\Http\Resources\MatchNotificationResource;
use App\Http\Resources\PersonSummary;
use App\Http\Resources\SeatRequestResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The two screens people open the app on: screen 9 for a passenger, screen 23 for a
 * driver.
 *
 * 🔴 Why these are aggregates rather than the client making five calls. Both screens
 * lead with a single card that has to be right as a whole — "your journey leaves in
 * 51 minutes, 2 of 3 are coming, meet at Gate 2". Assembled from five responses on a
 * phone, that card is five chances to render half of it: a departure time from one
 * call and an attendance count from another taken seconds apart can disagree, and the
 * first screen of the app is the worst place to show a person a contradiction. It is
 * also the screen most often opened on a bad connection at a bus stop.
 *
 * Read-only, so there is no Action: Actions exist to make changes safely, and nothing
 * here changes anything. The queries live in the controller for the same reason.
 *
 * 🔒 Every query is scoped to the caller. Neither screen has an id parameter, so there
 * is nothing here to point at somebody else's journey.
 */
final class HomeController extends Controller
{
    /** How many match cards screen 9 has room for before "See all". */
    private const int TOP_MATCHES = 3;

    /** The saved corridors strip, which scrolls sideways rather than forever. */
    private const int SAVED_SEARCHES = 5;

    /** Requests on the driver's card before it says "see all". */
    private const int PENDING_REQUESTS = 5;

    /**
     * GET /v1/home — screen 9.
     *
     * Deliberately NOT paginated, unlike every other list in the API. Each part is
     * capped at what the screen shows, so the response cannot grow with use: a
     * passenger with four hundred bookings gets the same size payload as one with
     * three. The full lists are their own paginated endpoints, which is what "See all"
     * goes to.
     */
    public function passenger(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            /*
             * "Ahlan, Mariam". The public first name and nothing else, for the same
             * reason it is the only name anywhere else (see PersonSummary) — and this
             * is the person's own screen, so it is their own name either way.
             */
            'greetingName' => $user->public_first_name,

            /*
             * The banner across the top of screen 9. The same read model the
             * Verification Centre is built from, so the banner cannot claim a level the
             * Centre disagrees with, and `isComplete` is stated rather than left to the
             * client to infer from two numbers.
             */
            'verification' => $this->verificationBanner($user),

            'nextJourney' => $this->nextJourney($request),
            'topMatches' => $this->topMatches($request),
            'savedSearches' => $this->savedSearches($user->id),
        ]);
    }

    /**
     * GET /v1/driver/home — screen 23.
     *
     * The largest card in the product: today's run, who is on it, what is owed, and
     * what is still waiting to be answered.
     */
    public function driver(Request $request): JsonResponse
    {
        $profile = DriverProfile::query()
            ->whereKey($request->user()->id)
            ->first();

        /*
         * A passenger who has never applied to drive. Answered as an empty driver home
         * rather than a 404, because the app switches roles with a button on this very
         * screen: "you have no run today" is the truthful answer, and a 404 would make
         * the role switch look broken.
         */
        if ($profile === null) {
            return ApiResponse::success([
                'greetingName' => $request->user()->public_first_name,
                'nextRun' => null,
                'pendingRequests' => ['count' => 0, 'items' => []],
                'stats' => ['onTimeRate' => null, 'completedTrips' => 0, 'avgDetourMinutes' => null],
            ]);
        }

        return ApiResponse::success([
            'greetingName' => $request->user()->public_first_name,
            'nextRun' => $this->nextRun($request, $profile),
            'pendingRequests' => $this->pendingRequests($request, $profile->user_id),

            /*
             * The three figures in a row under the card: "98% reliability · +6′ avg
             * detour · 1 seat open". The seats live on the run, since they are about
             * today; these two are about the driver.
             */
            'stats' => [
                /*
                 * Null until Phase 9 computes it from completed trips, and null is the
                 * honest answer: a driver with no history has no on-time rate, and
                 * sending 0 would put a 0% badge on somebody's first morning.
                 */
                'onTimeRate' => $profile->on_time_rate === null ? null : (float) $profile->on_time_rate,
                'completedTrips' => $profile->completed_trips_count,
                'avgDetourMinutes' => $this->averageDetourMinutes($profile->user_id),
            ],
        ]);
    }

    /**
     * @return array{level: int, of: int, percentage: int, isComplete: bool}
     */
    private function verificationBanner(User $user): array
    {
        $centre = VerificationCentre::for($user);

        return [
            'level' => $centre['level'],
            'of' => $centre['of'],
            'percentage' => $centre['percentage'],
            'isComplete' => $centre['level'] >= $centre['of'],
        ];
    }

    /**
     * The card at the top of screen 9: the caller's soonest journey that has not
     * finished.
     *
     * @return array<string, mixed>|null
     */
    private function nextJourney(Request $request): ?array
    {
        $booking = Booking::query()
            ->where('bookings.passenger_user_id', $request->user()->id)
            ->whereIn('bookings.status', $this->liveBookingStatuses())
            /*
             * Joined rather than filtered with whereHas: the card is "the SOONEST one",
             * and ordering by a column on the related trip is what picks it. An
             * unordered whereHas would return whichever row the database happened to
             * find first.
             */
            ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
            ->where(fn (Builder $query) => $this->currentTrips($query))
            ->orderBy('scheduled_trips.departure_at')
            // A tiebreak, because two journeys can leave in the same minute.
            ->orderBy('bookings.id')
            ->select('bookings.*')
            ->with([
                'scheduledTrip.commuteSchedule',
                'scheduledTrip.commuteOffer.locations',
                'scheduledTrip.commuteOffer.vehicle',
                'scheduledTrip.commuteOffer.driverProfile.user.stats',
                'scheduledTrip.commuteOffer.driverProfile.user.verifications',
                'commuteGroup.activeMembers',
                'commuteGroup.attendances',
                'seatRequest',
            ])
            ->first();

        if ($booking === null) {
            return null;
        }

        $trip = $booking->scheduledTrip;
        $offer = $trip->commuteOffer;
        $driver = $offer->driverProfile;

        return [
            /*
             * The booking in full, from the resource that already owns the rule about
             * when a meeting point may be exact. Composed rather than re-described, so
             * the home screen cannot end up with a more generous version of it.
             */
            ...(new BookingResource($booking))->toArray($request),

            ...JourneyCard::route($offer),
            ...JourneyCard::timing($trip),
            'attendance' => JourneyCard::attendance($booking->commuteGroup, $trip),

            /*
             * "Trial tomorrow" on the card. Read from the request that produced the
             * booking, because a trial is a property of the arrangement rather than of
             * the day: one trial ride, then a decision.
             */
            'isTrial' => $booking->seatRequest?->commitment === SeatRequestCommitment::Trial,

            'driver' => [
                ...PersonSummary::for($driver->user, $request->user(), asDriver: true),
                // From the driver profile, not `user_stats`: the profile's counter is the
                // one publishing keeps current (see MatchResultResource).
                'completedTrips' => $driver->completed_trips_count,
            ],

            'vehicle' => $this->vehicleCard($offer, $booking),
        ];
    }

    /**
     * The car to look for.
     *
     * 🔒 The plate appears only once the booking is CONFIRMED, under the same rule as
     * the exact meeting point — a plate identifies the car outside somebody's house, and
     * a pending booking has not earned it. Search results never carry it at all.
     *
     * Colour and model always: that much is how a passenger tells a silver Sportage from
     * a white one at a busy gate, and it identifies nobody on its own.
     *
     * @return array<string, mixed>
     */
    private function vehicleCard(CommuteOffer $offer, Booking $booking): array
    {
        return [
            'make' => $offer->vehicle?->make,
            'model' => $offer->vehicle?->model,
            'colour' => $offer->vehicle?->colour,
            // Total seats including the driver's — "3-seat group" on the card.
            'seats' => $offer->vehicle?->seats,
            'plateNumber' => $booking->status->grantsExactDetails()
                ? $offer->vehicle?->plate_number
                : null,
        ];
    }

    /**
     * "Matches on your route" — commutes found for the caller's saved requests.
     *
     * Ordered by score rather than by when they were found: the strip has room for three,
     * and the three best matches are more useful than the three most recent. The full
     * list, newest first, is `GET /v1/matches`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topMatches(Request $request): array
    {
        $notifications = MatchNotification::query()
            ->whereIn('commute_demand_id', CommuteDemand::query()
                ->where('passenger_user_id', $request->user()->id)
                ->select('id'))
            ->with([
                'commuteOffer.vehicle',
                'commuteOffer.driverProfile.user',
                'commuteOffer.locations',
                'commuteOffer.schedule',
            ])
            ->orderByDesc('score')
            ->orderByDesc('id')
            ->limit(self::TOP_MATCHES)
            ->get();

        $cards = [];

        // foreach, not map: a mapped collection loses its element type in the published
        // contract, which is the one thing the mobile team cannot guess at.
        foreach ($notifications as $notification) {
            $cards[] = (new MatchNotificationResource($notification))->toArray($request);
        }

        return $cards;
    }

    /**
     * The "Saved corridors" strip.
     *
     * No match count per card: the prototype's card carries a title and a "Saved" badge,
     * and a count would mean running every saved search on every home load — the most
     * expensive query in the product, several times, to render a number nobody asked for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function savedSearches(string $userId): array
    {
        $searches = SavedSearch::query()
            ->where('user_id', $userId)
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(self::SAVED_SEARCHES)
            ->get();

        $cards = [];

        foreach ($searches as $search) {
            $cards[] = [
                'id' => $search->id,
                'title' => $search->title,
            ];
        }

        return $cards;
    }

    /**
     * Today's route on screen 23: the driver's soonest run that has not finished.
     *
     * @return array<string, mixed>|null
     */
    private function nextRun(Request $request, DriverProfile $profile): ?array
    {
        $trip = ScheduledTrip::query()
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $profile->user_id)
                ->select('id'))
            ->where(fn (Builder $query) => $this->currentTrips($query, 'scheduled_trips'))
            ->orderBy('departure_at')
            ->orderBy('id')
            ->with([
                'commuteSchedule',
                'commuteOffer.locations',
                'commuteOffer.vehicle',
                'commuteOffer.group.activeMembers',
                'commuteOffer.group.attendances',
                'bookings.passenger.stats',
                'bookings.passenger.verifications',
                'bookings.seatRequest',
            ])
            ->first();

        if ($trip === null) {
            return null;
        }

        $offer = $trip->commuteOffer;
        $group = $offer->group;

        $confirmed = $trip->bookings->filter(
            fn (Booking $booking) => in_array($booking->status, $this->liveBookingStatuses(), true),
        );

        return [
            'commuteId' => $offer->id,
            'tripId' => $trip->id,
            'groupId' => $group?->id,
            'status' => strtoupper($trip->status->value),

            ...JourneyCard::route($offer),
            ...JourneyCard::timing($trip),
            'attendance' => JourneyCard::attendance($group, $trip),

            'seatsTotal' => $trip->seats_total,
            'seatsTaken' => $trip->seats_taken,
            // "1 seat open" — cast because max() returns the wider of its arguments'
            // types, which the contract would then describe as untyped.
            'seatsOpen' => (int) max(0, $trip->seats_total - $trip->seats_taken),

            /*
             * 🔴 Two figures, not the screen's one "You collect EGP 240", because with a
             * cash commute those are different numbers and the driver needs both: what
             * to take at the door, and what of it is theirs once the platform's fee is
             * settled.
             *
             * Both are sums of what was FROZEN on each booking at approval, never
             * recomputed — so a price change tomorrow moves neither, and whichever way
             * the open question about the fee's direction is settled (see the screen map,
             * §8.1), this keeps reporting what those bookings actually say.
             */
            'collectPiastres' => (int) $confirmed->sum('price_snapshot_piastres'),
            'keepPiastres' => (int) $confirmed->sum('driver_amount_snapshot_piastres'),

            'passengers' => $this->runPassengers($request, $confirmed, $group, $trip),
        ];
    }

    /**
     * "Approved passengers" on screen 23, each with their meeting point and whether they
     * said they are coming.
     *
     * The driver gets the exact point: they have to drive to it, and they were the one
     * who approved it. The restraint that stays is on the person — a public first name
     * and what has been verified, never a full name or a phone number.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return array<int, array<string, mixed>>
     */
    private function runPassengers(
        Request $request,
        Collection $bookings,
        ?CommuteGroup $group,
        ScheduledTrip $trip,
    ): array {
        $declared = $group === null
            ? collect()
            : $group->attendances->where('scheduled_trip_id', $trip->id)->keyBy('user_id');

        $rows = [];

        foreach ($bookings as $booking) {
            $point = $booking->pickup_point;

            $rows[] = [
                'bookingId' => $booking->id,
                'person' => PersonSummary::for($booking->passenger, $request->user()),
                'commitment' => $booking->seatRequest?->commitment->value,
                'requestedDaysMask' => $booking->seatRequest?->requested_days_mask,
                'seatsReserved' => $booking->seats_reserved,

                /*
                 * NULL means the standard meeting point — the run's own origin, which
                 * this payload already carries as `originLabel`. A booking only stores a
                 * point of its own once a CUSTOM pickup was proposed and approved; a
                 * passenger meeting the driver at the gate has no separate point,
                 * because the gate is where the driver already starts.
                 *
                 * Stated here because the alternative is copying the origin onto every
                 * row, which would make "we agreed to collect her here" and "she comes
                 * to the gate like everybody else" look identical to the driver.
                 */
                'pickup' => $point === null ? null : [
                    'lat' => (float) $point->lat,
                    'lng' => (float) $point->lng,
                ],
                /*
                 * "Coming" / "Away today" on the card. Null when they have not answered,
                 * which is not the same as away — see JourneyCard::attendance().
                 */
                'attendanceStatus' => $declared->get($booking->passenger_user_id)?->status->value,
            ];
        }

        return $rows;
    }

    /**
     * Seat requests still waiting for an answer, across all of this driver's commutes.
     *
     * The count is of ALL of them and the list is the first few: a driver with eleven
     * requests should see "11", not "5", or the card would quietly hide work from them.
     *
     * @return array{count: int, items: array<int, array<string, mixed>>}
     */
    private function pendingRequests(Request $request, string $driverProfileId): array
    {
        $query = SeatRequest::query()
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $driverProfileId)
                ->select('id'))
            ->where('status', SeatRequestStatus::Pending);

        $count = (clone $query)->count();

        $requests = $query
            ->with(['passenger.stats', 'passenger.verifications'])
            // Oldest first: the person who has been waiting longest is the one whose
            // answer is most overdue, and a request expires after 48 hours.
            ->oldest('created_at')
            ->orderBy('id')
            ->limit(self::PENDING_REQUESTS)
            ->get();

        $items = [];

        foreach ($requests as $seatRequest) {
            $items[] = (new SeatRequestResource($seatRequest))->toArray($request);
        }

        return ['count' => $count, 'items' => $items];
    }

    /**
     * "+6′ avg detour" — how much longer the run is because of pickups people asked for.
     *
     * Averaged over APPROVED pickup point requests only, and over the minutes WE
     * measured rather than any figure a requester offered. Zero when nobody has an
     * approved custom pickup, which is the true answer rather than a missing one:
     * everybody meets where the driver already drives, so the detour is none.
     *
     * Null only when the driver has no commutes at all, where there is nothing to
     * average.
     */
    private function averageDetourMinutes(string $driverProfileId): ?float
    {
        $offers = CommuteOffer::query()
            ->where('driver_profile_id', $driverProfileId)
            ->pluck('id');

        if ($offers->isEmpty()) {
            return null;
        }

        /*
         * Either side of the request can be the link: a new joiner's request hangs off
         * their seat request, an existing member's off their membership. Both paths are
         * followed, because averaging one of them would silently report the detour of
         * whichever half of the group asked more recently.
         */
        $average = PickupPointRequest::query()
            ->where('status', PickupPointRequestStatus::Approved)
            ->where(function (Builder $query) use ($offers): void {
                $query
                    ->whereIn('seat_request_id', SeatRequest::query()
                        ->whereIn('commute_offer_id', $offers)
                        ->select('id'))
                    ->orWhereIn('group_member_id', GroupMember::query()
                        ->whereIn('commute_group_id', CommuteGroup::query()
                            ->whereIn('commute_offer_id', $offers)
                            ->select('id'))
                        ->select('id'));
            })
            ->avg('added_minutes');

        return round((float) $average, 1);
    }

    /**
     * A trip that has not finished: either it has not left yet, or it is underway.
     *
     * Written as a condition rather than a time window because a window has to guess how
     * long a commute lasts — and a run that is two hours late is exactly the run the
     * driver most needs to still see on their screen.
     */
    private function currentTrips(Builder $query, string $table = 'scheduled_trips'): void
    {
        $query
            ->where(fn (Builder $inner) => $inner
                ->where("{$table}.departure_at", '>=', now())
                ->orWhereIn("{$table}.status", [
                    ScheduledTripStatus::Preparing,
                    ScheduledTripStatus::EnRoute,
                    ScheduledTripStatus::InProgress,
                ]))
            ->whereNotIn("{$table}.status", [
                ScheduledTripStatus::Completed,
                ScheduledTripStatus::Cancelled,
            ]);
    }

    /**
     * The statuses at which a seat is still held for a journey that has not happened.
     *
     * Narrower than the list the duplicate check uses, on purpose: that one also counts
     * `completed`, because having ridden a day is a reason not to book it again. Neither
     * screen is showing a day somebody already rode.
     *
     * @return array<int, BookingStatus>
     */
    private function liveBookingStatuses(): array
    {
        return [BookingStatus::Pending, BookingStatus::Confirmed];
    }
}
