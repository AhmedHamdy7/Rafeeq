<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PickupPointRequestStatus;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Enums\SwitchReason;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\ComputedDeparture;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Actions\SuggestAlternativesAction;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use App\Domains\Matching\Models\SavedSearch;
use App\Domains\Matching\Models\SearchHistory;
use App\Domains\Matching\Support\SearchCriteria;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Models\Notification;
use App\Domains\Rating\Actions\SubmitRatingAction;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Verification\Support\VerificationCentre;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\HomeJourney;
use App\Http\Resources\JourneyCard;
use App\Http\Resources\MatchNotificationResource;
use App\Http\Resources\PersonSummary;
use App\Http\Resources\SeatRequestResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

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

    /** "Two suggested alternatives" under an ended journey (F3P-08 → F3P-10). */
    private const int ALTERNATIVES = 2;

    /**
     * How long one ended journey's alternatives are reused, in seconds.
     *
     * 🔴 The alternatives are the real search engine — the same one `GET /bookings/{booking}/
     * alternatives` runs, ending in routing-provider work — and Home is polled. Without this, every
     * pull-to-refresh on a cancelled morning would be a full search. Five minutes is short enough
     * that a seat taken meanwhile is soon gone from the card, and asking for it is the ordinary
     * request flow, which re-checks the seat anyway.
     */
    private const int ALTERNATIVES_TTL_SECONDS = 300;

    private const int LATER_THIS_WEEK = 7;

    private const int LATER_THIS_WEEK_DAYS = 7;

    private const int DRIVER_NOTICES = 5;

    private const int DRIVER_NOTICE_HOURS = 24;

    private const int LEAVE_BY_HORIZON_MINUTES = 180;

    private const int LEAVE_BY_TTL_SECONDS = 300;

    /**
     * GET /v1/home — screen 9.
     *
     * Deliberately NOT paginated, unlike every other list in the API. Each part is
     * capped at what the screen shows, so the response cannot grow with use: a
     * passenger with four hundred bookings gets the same size payload as one with
     * three. The full lists are their own paginated endpoints, which is what "See all"
     * goes to.
     */
    public function passenger(
        Request $request,
        SubmitRatingAction $ratings,
        SuggestAlternativesAction $alternatives,
    ): JsonResponse {
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

            /*
             * The bell badge (HOME-10). The same count `GET /notifications` puts in
             * `meta.unreadCount`, from the same query, so Home is one request and the badge
             * cannot disagree with the inbox behind it.
             */
            'unreadNotifications' => $this->unreadNotifications($user->id),

            'nextJourney' => $this->nextJourney($request, $ratings, $alternatives),
            'demandAlert' => $this->demandAlert($user->id),
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
    public function driver(Request $request, ComputedDeparture $departures): JsonResponse
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
                'laterThisWeek' => [],
                'notices' => [],
                'pendingRequests' => ['count' => 0, 'items' => []],
                'stats' => ['onTimeRate' => null, 'completedTrips' => 0, 'avgDetourMinutes' => null],
            ]);
        }

        return ApiResponse::success([
            'greetingName' => $request->user()->public_first_name,
            'nextRun' => $nextRun = $this->nextRun($request, $profile, $departures),
            'laterThisWeek' => $this->laterThisWeek($profile, $nextRun['tripId'] ?? null),
            'notices' => $this->driverNotices($request, $profile),
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
     * `null` for a passenger-only account — passengers never verify, so there is no banner to
     * show her (PROF-12, follow-up F2-8).
     *
     * @return array{level: int, of: int, percentage: int, isComplete: bool}|null
     */
    private function verificationBanner(User $user): ?array
    {
        if (! VerificationCentre::appliesTo($user)) {
            return null;
        }

        $centre = VerificationCentre::for($user);

        return [
            'level' => $centre['level'],
            'of' => $centre['of'],
            'percentage' => $centre['percentage'],
            'isComplete' => $centre['level'] >= $centre['of'],
        ];
    }

    /**
     * The card at the top of screen 9 — which journey it is about (HOME-1).
     *
     * In this order, the first that exists:
     *
     * 1. **The journey happening now** — its departure has passed or its run has set off, and her
     *    seat is still held. A car on the way to her outranks anything else she could be shown.
     * 2. **A journey that ended on her today** — cancelled by the driver, expired, she left because
     *    the driver was late, or completed. Until the end of that journey's local day, because the
     *    outcome is news she has to have seen, and "your 07:00 was cancelled" vanishing at 07:01
     *    would leave her at the kerb wondering whether the app forgot.
     * 3. **The soonest journey that has not started** — what this card has always been.
     * 4. **A completed journey she has not rated**, while the rating window is open — but only when
     *    there is nothing upcoming to show. A ride from four days ago asking for stars must not hide
     *    tomorrow's pickup; once tomorrow exists, the rating is still in `GET /ratings/pending`.
     *
     * ⚠️ "Until she has seen it" is approximated by time, not tracked: nothing records that she
     * looked at the card, and adding a write to a read-only screen the app polls would be the wrong
     * place for it. The windows above are the ones the request proposed.
     *
     * @return array<string, mixed>|null
     */
    private function nextJourney(
        Request $request,
        SubmitRatingAction $ratings,
        SuggestAlternativesAction $alternatives,
    ): ?array {
        $userId = $request->user()->id;

        $upcoming = $this->currentBooking($userId);

        $booking = $this->happeningNow($userId)
            ?? $this->endedToday($userId, $ratings)
            ?? $upcoming
            ?? $this->awaitingRating($userId, $ratings);

        return $booking === null ? null : $this->journeyCard($request, $booking, $ratings, $alternatives);
    }

    /**
     * Rows 1 and 3 of the order above share one query: a confirmed seat on a run that has not
     * finished, soonest first. A pending request is not a commute yet (TRIP-01).
     */
    private function currentBooking(string $userId): ?Booking
    {
        return $this->journeyQuery()
            ->where('bookings.passenger_user_id', $userId)
            ->where('bookings.status', BookingStatus::Confirmed)
            /*
             * Joined rather than filtered with whereHas: the card is "the SOONEST one",
             * and ordering by a column on the related trip is what picks it. An
             * unordered whereHas would return whichever row the database happened to
             * find first.
             */
            ->where(fn (Builder $query) => $this->currentTrips($query))
            ->orderBy('scheduled_trips.departure_at')
            // A tiebreak, because two journeys can leave in the same minute.
            ->orderBy('bookings.id')
            ->first();
    }

    /**
     * Row 1: a CONFIRMED seat on a run that is underway, or whose departure has passed without it
     * setting off (F3P-07, until the sweep expires the day). Today only — a morning the sweep has
     * not reached yet is still this morning, and yesterday's is not "now".
     */
    private function happeningNow(string $userId): ?Booking
    {
        return $this->journeyQuery()
            ->where('bookings.passenger_user_id', $userId)
            ->where('bookings.status', BookingStatus::Confirmed)
            ->where(fn (Builder $query) => $query
                ->whereIn('scheduled_trips.status', [
                    ScheduledTripStatus::Preparing,
                    ScheduledTripStatus::EnRoute,
                    ScheduledTripStatus::InProgress,
                ])
                ->orWhere(fn (Builder $late) => $late
                    ->where('scheduled_trips.status', ScheduledTripStatus::Scheduled)
                    ->where('scheduled_trips.departure_at', '<=', now())
                    // A generous bound for the query; the local-day test below is the real one.
                    ->where('scheduled_trips.departure_at', '>=', now()->subDay())))
            ->orderBy('scheduled_trips.departure_at')
            ->orderBy('bookings.id')
            ->get()
            ->first(fn (Booking $candidate): bool => $candidate->scheduledTrip->status !== ScheduledTripStatus::Scheduled
                || $this->isTodayFor($candidate->scheduledTrip));
    }

    /**
     * Row 2: a journey whose outcome she has to see — on its own local day. The most recent outcome
     * first, because the newest news is the card's to give.
     */
    private function endedToday(string $userId, SubmitRatingAction $ratings): ?Booking
    {
        return $this->journeyQuery()
            ->where('bookings.passenger_user_id', $userId)
            ->whereIn('bookings.status', [
                BookingStatus::CancelledByDriver,
                BookingStatus::ExitedDueToDriverDelay,
                BookingStatus::Completed,
            ])
            // Bounded in SQL so a long history is not loaded to find today; exact below.
            ->where('scheduled_trips.departure_at', '>=', now()->subDays(2))
            ->where('scheduled_trips.departure_at', '<=', now()->addDay())
            ->orderByDesc('bookings.updated_at')
            ->orderByDesc('bookings.id')
            ->get()
            ->first(fn (Booking $candidate): bool => $this->isTodayFor($candidate->scheduledTrip)
                // A completed ride she has already rated has nothing left to say to her.
                && ($candidate->status !== BookingStatus::Completed
                    || HomeJourney::for($candidate, $ratings, $userId)->canRate()));
    }

    /**
     * Row 4: the most recent completed ride still waiting for her stars.
     */
    private function awaitingRating(string $userId, SubmitRatingAction $ratings): ?Booking
    {
        return $this->journeyQuery()
            ->where('bookings.passenger_user_id', $userId)
            ->where('bookings.status', BookingStatus::Completed)
            ->whereDoesntHave('ratings', fn (Builder $query) => $query->where('reviewer_user_id', $userId))
            // No window is longer than this; the exact deadline is RatingSettings', checked below.
            ->where('scheduled_trips.departure_at', '>=', now()->subDays(31))
            ->orderByDesc('scheduled_trips.departure_at')
            ->orderByDesc('bookings.id')
            ->limit(5)
            ->get()
            ->first(fn (Booking $candidate): bool => ! $ratings->deadlineFor($candidate)->isPast());
    }

    /**
     * Bookings joined to their day, with everything the card reads.
     *
     * Joined rather than filtered with whereHas: the card is "the SOONEST one" (or the latest
     * outcome), and ordering by a column on the related trip is what picks it. An unordered
     * whereHas would return whichever row the database happened to find first.
     *
     * @return Builder<Booking>
     */
    private function journeyQuery(): Builder
    {
        return Booking::query()
            ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
            ->select('bookings.*')
            ->with([
                'scheduledTrip.commuteSchedule',
                'scheduledTrip.tripSession',
                'scheduledTrip.commuteOffer.locations',
                'scheduledTrip.commuteOffer.vehicle',
                'scheduledTrip.commuteOffer.driverProfile.user.stats',
                'scheduledTrip.commuteOffer.driverProfile.user.verifications',
                'commuteGroup.activeMembers',
                'commuteGroup.attendances',
                'seatRequest',
                'passenger',
            ]);
    }

    /**
     * Whether the day of this journey is today, on the commute's own wall clock — the same test
     * `isToday` on the card makes. Cairo's day, not the server's UTC one.
     */
    private function isTodayFor(ScheduledTrip $trip): bool
    {
        $timezone = $trip->commuteSchedule?->timezone ?? config('app.timezone');

        return $trip->departure_local->isSameDay(Carbon::now($timezone));
    }

    /**
     * @return array<string, mixed>
     */
    private function journeyCard(
        Request $request,
        Booking $booking,
        SubmitRatingAction $ratings,
        SuggestAlternativesAction $alternatives,
    ): array {
        $trip = $booking->scheduledTrip;
        $offer = $trip->commuteOffer;
        $driver = $offer->driverProfile;

        $state = HomeJourney::for($booking, $ratings, $request->user()->id);
        $phase = $state->phase();

        /*
         * The booking in full, from the resource that already owns the rule about when a meeting
         * point may be exact. Composed rather than re-described, so the home screen cannot end up
         * with a more generous version of it — and `pickup` below is built FROM its `meetingPoint`
         * for the same reason.
         */
        $resource = (new BookingResource($booking))->toArray($request);
        $route = JourneyCard::route($offer);

        return [
            ...$resource,

            // The state the card switches on (HOME-2). `status` and `trip.status` are unchanged.
            'phase' => $phase,

            ...$route,
            ...JourneyCard::timing($trip),
            'attendance' => JourneyCard::attendance($booking->commuteGroup, $trip),

            // "Every Sun, Tue, Thu" (HOME-3).
            'daysMask' => $state->daysMask(),
            'pickup' => $state->pickup($resource['meetingPoint'], $route['originLabel']),
            'dropoff' => $state->dropoff($resource['dropoffPoint'], $route['destinationLabel']),

            // F3P-04 → F3P-06 (HOME-4). Null before the driver starts and after the ride.
            'live' => $state->live(),

            'checkIn' => $state->checkIn(),

            // F3P-07 (HOME-5). The button calls `POST /bookings/{booking}/exit-without-fee`.
            'canLeaveWithoutFee' => $state->canLeaveWithoutFee(),

            // F3P-08 → F3P-10 (HOME-6). `cancellationFeePiastres` is already on the booking.
            'endedAt' => $state->endedAt(),
            'alternatives' => in_array($phase, HomeJourney::ENDED, true)
                ? $this->alternatives($booking, $alternatives)
                : [],

            // F3P-11 (HOME-7).
            'completedAt' => $state->completedAt()?->toIso8601String(),
            'canRate' => $state->canRate(),

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
     * "07:30 · Karim · Fifth Settlement → Smart Village B12 · 2 seats" — up to two commutes she
     * could still ask on, under a journey that was taken away from her (HOME-6).
     *
     * 🔴 The SAME suggestion `GET /bookings/{booking}/alternatives` makes, cut to two and to the
     * card's fields, so the card and the "Find Alternative" screen behind it cannot disagree about
     * what exists. It books nothing, like that endpoint.
     *
     * An empty list, never an error, when nothing fits or the booking has no recorded drop-off to
     * search towards — the card simply shows no alternatives, and Home must not fail because a
     * suggestion could not be made.
     *
     * @return array<int, array<string, mixed>>
     */
    private function alternatives(Booking $booking, SuggestAlternativesAction $action): array
    {
        return Cache::remember(
            "home:alternatives:{$booking->getKey()}",
            self::ALTERNATIVES_TTL_SECONDS,
            function () use ($booking, $action): array {
                try {
                    $results = $action->execute($booking)['results'];
                } catch (DomainException) {
                    return [];
                }

                $cards = [];

                foreach (array_slice($results, 0, self::ALTERNATIVES) as $result) {
                    $offer = $result->offer;

                    $cards[] = [
                        'commuteId' => $offer->id,
                        // The day it fits on — what a trial or a seat request is made against.
                        'tripId' => $result->trip->id,
                        'departureAt' => $result->trip->departure_at->toIso8601String(),
                        'departureLocal' => $result->trip->departure_local->format('Y-m-d H:i:s'),
                        ...JourneyCard::route($offer),
                        'seatsAvailable' => (int) max(0, $result->trip->seats_total - $result->trip->seats_taken),
                        // A first name and nothing else, as on every suggestion (PersonSummary).
                        'driver' => ['publicFirstName' => $offer->driverProfile->user->public_first_name],
                    ];
                }

                return $cards;
            },
        );
    }

    /**
     * "3 new commutes match your demand · New Cairo → Smart Village · Sun–Thu · 08:00" (HOME-8).
     *
     * ONE alert: the active demand with the most matches she has not opened, the most recently
     * created demand on a tie. Null when no demand has an unopened match — the card is news, and
     * "0 new commutes" is not.
     *
     * "Opened" is `match_notifications.clicked_at`, which `POST /matches/{match}/opened` stamps
     * when she taps a match. Without that call every match stays new forever, so the app MUST send
     * it.
     *
     * @return array<string, mixed>|null
     */
    private function demandAlert(string $userId): ?array
    {
        $demand = CommuteDemand::query()
            ->where('passenger_user_id', $userId)
            ->where('status', CommuteDemandStatus::Active)
            ->select('commute_demands.*')
            ->selectSub(MatchNotification::query()
                ->selectRaw('count(*)')
                ->whereColumn('match_notifications.commute_demand_id', 'commute_demands.id')
                ->whereNull('match_notifications.clicked_at'), 'unopened_count')
            ->orderByDesc('unopened_count')
            ->latest('created_at')
            ->orderByDesc('id')
            ->first();

        if ($demand === null || (int) $demand->unopened_count === 0) {
            return null;
        }

        return [
            'demandId' => $demand->id,
            'newMatchesCount' => (int) $demand->unopened_count,
            'originLabel' => $demand->origin_label,
            'destinationLabel' => $demand->destination_label,
            'daysMask' => (int) $demand->days_mask,
            // "08:00" — the start of the window she asked to arrive in; the end is beside it.
            'arrivalTimeLocal' => substr((string) $demand->preferred_arrival_start, 0, 5),
            'arrivalWindowEndLocal' => substr((string) $demand->preferred_arrival_end, 0, 5),
        ];
    }

    /**
     * The same count as `GET /notifications`' `meta.unreadCount`: in-app rows only, unread.
     */
    private function unreadNotifications(string $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('channel', NotificationChannel::InApp->value)
            ->whereNull('read_at')
            ->count();
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
                'commuteOffer.driverProfile',
                'commuteDemand',
            ])
            ->orderByDesc('score')
            ->orderByDesc('id')
            ->limit(self::TOP_MATCHES)
            ->get();

        $nextDays = $this->nextOpenDays($notifications->pluck('commute_offer_id')->all());

        $cards = [];

        // foreach, not map: a mapped collection loses its element type in the published
        // contract, which is the one thing the mobile team cannot guess at.
        foreach ($notifications as $notification) {
            $card = (new MatchNotificationResource($notification))->toArray($request);
            $demand = $notification->commuteDemand;
            $next = $nextDays->get($notification->commute_offer_id);

            /*
             * HOME-9, added HERE rather than on MatchNotificationResource: `GET /matches` shares
             * that resource and has its own consumers, and these three answer questions only this
             * strip asks.
             */
            $cards[] = [
                ...$card,

                /*
                 * "Matches "New Cairo → Smart Village"". A demand has no title of its own, so it
                 * is named by the route she typed; null when either end was never labelled, and
                 * the row then says "Matches your demand".
                 */
                'demandTitle' => $demand?->origin_label !== null && $demand->destination_label !== null
                    ? "{$demand->origin_label} → {$demand->destination_label}"
                    : null,

                /*
                 * "2 seats left" — on the commute's SOONEST day still open to booking, which is the
                 * day a request from this row would be made on. Null when it has no such day.
                 */
                'seatsAvailable' => $next === null ? null : (int) max(0, $next->seats_total - $next->seats_taken),

                'driver' => [
                    ...$card['driver'],
                    // 0 → "New driver". The profile's counter, as on every other card.
                    'completedTrips' => (int) $notification->commuteOffer->driverProfile->completed_trips_count,
                ],
            ];
        }

        return $cards;
    }

    /**
     * Each commute's soonest day that has not left, in one query for the whole strip.
     *
     * @param  array<int, string>  $offerIds
     * @return Collection<string, ScheduledTrip>
     */
    private function nextOpenDays(array $offerIds): Collection
    {
        if ($offerIds === []) {
            return collect();
        }

        return ScheduledTrip::query()
            ->whereIn('commute_offer_id', $offerIds)
            ->where('status', ScheduledTripStatus::Scheduled)
            ->where('departure_at', '>=', now())
            ->orderBy('departure_at')
            ->orderBy('id')
            ->get(['id', 'commute_offer_id', 'departure_at', 'seats_total', 'seats_taken'])
            ->unique('commute_offer_id')
            ->keyBy('commute_offer_id');
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

        $signatures = [];

        foreach ($searches as $search) {
            $signatures[$search->id] = $this->searchSignature($search, $userId);
        }

        /*
         * "You searched this on Tuesday" (HOME-9). When she last RAN this search, from her own
         * recent-search history — matched on the same signature that history and the result cache
         * use, so "the same search" means one thing across the feature. One query for the strip.
         */
        $lastSearched = SearchHistory::query()
            ->where('user_id', $userId)
            ->whereIn('signature', array_values(array_filter($signatures)))
            ->pluck('searched_at', 'signature');

        $cards = [];

        foreach ($searches as $search) {
            $signature = $signatures[$search->id];
            $at = $signature === null ? null : $lastSearched->get($signature);

            $cards[] = [
                'id' => $search->id,
                'title' => $search->title,
                // Null when she saved it and has not run it since (history is pruned after a while).
                'lastSearchedAt' => $at === null ? null : Carbon::parse($at)->toIso8601String(),
            ];
        }

        return $cards;
    }

    /**
     * The recent-search signature of a saved search's filters, or null for filters saved before
     * the current search shape that no longer parse — such a card simply has no "last searched".
     */
    private function searchSignature(SavedSearch $search, string $userId): ?string
    {
        try {
            return SearchCriteria::fromArray($search->filters)->signature($userId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Today's route on screen 23: the driver's soonest run that has not finished.
     *
     * @return array<string, mixed>|null
     */
    private function nextRun(Request $request, DriverProfile $profile, ComputedDeparture $departures): ?array
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
            'leaveBy' => $this->leaveBy($trip, $departures),
            'attendance' => JourneyCard::attendance($group, $trip),

            'seatsTotal' => $trip->seats_total,
            'seatsTaken' => $trip->seats_taken,
            // "1 seat open" — cast because max() returns the wider of its arguments'
            // types, which the contract would then describe as untyped.
            'seatsOpen' => (int) max(0, $trip->seats_total - $trip->seats_taken),

            /*
             * 🔴 Two figures, not the screen's one "You collect EGP 240", because with a
             * cash commute those are different numbers and the driver needs both: what
             * to take at the door (the seat prices), and what of it is his once RAFEEQ's
             * commission — added on top of his share (PAY-48) — is settled.
             *
             * Both are sums of what was FROZEN on each booking at approval, never
             * recomputed — so a rate change tomorrow moves neither.
             */
            'collectPiastres' => (int) $confirmed->sum('price_snapshot_piastres'),
            'keepPiastres' => (int) $confirmed->sum('driver_amount_snapshot_piastres'),

            'passengers' => $this->runPassengers($request, $confirmed, $group, $trip),
        ];
    }

    /**
     * @return array{at: string, local: string, lateWarning: bool}|null
     */
    private function leaveBy(ScheduledTrip $trip, ComputedDeparture $departures): ?array
    {
        if ($trip->status !== ScheduledTripStatus::Scheduled
            || $trip->departure_at === null
            || $trip->departure_at->isPast()
            || now()->diffInMinutes($trip->departure_at) > self::LEAVE_BY_HORIZON_MINUTES) {
            return null;
        }

        $at = Cache::remember(
            "driver-home:leave-by:{$trip->getKey()}",
            self::LEAVE_BY_TTL_SECONDS,
            fn (): ?string => $departures->forTrip($trip)?->toIso8601String(),
        );

        if ($at === null) {
            return null;
        }

        $leaveBy = CarbonImmutable::parse($at);
        $timezone = $trip->commuteSchedule?->timezone ?? config('app.timezone');

        return [
            'at' => $leaveBy->toIso8601String(),
            'local' => $leaveBy->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'lateWarning' => $leaveBy->lessThan($trip->departure_at),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function laterThisWeek(DriverProfile $profile, ?string $nextTripId): array
    {
        $trips = ScheduledTrip::query()
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $profile->user_id)
                ->select('id'))
            ->where('status', ScheduledTripStatus::Scheduled)
            ->where('departure_at', '>=', now())
            ->where('departure_at', '<=', now()->addDays(self::LATER_THIS_WEEK_DAYS))
            ->when($nextTripId !== null, fn (Builder $query) => $query->whereKeyNot($nextTripId))
            ->orderBy('departure_at')
            ->orderBy('id')
            ->limit(self::LATER_THIS_WEEK)
            ->with(['commuteSchedule', 'commuteOffer.locations'])
            ->get();

        $rows = [];

        foreach ($trips as $trip) {
            $rows[] = [
                'commuteId' => $trip->commute_offer_id,
                'tripId' => $trip->id,
                ...JourneyCard::route($trip->commuteOffer),
                'departureAt' => $trip->departure_at->toIso8601String(),
                'departureLocal' => $trip->departure_local->format('Y-m-d H:i:s'),
                'seatsTotal' => $trip->seats_total,
                'seatsTaken' => $trip->seats_taken,
                'seatsOpen' => (int) max(0, $trip->seats_total - $trip->seats_taken),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function driverNotices(Request $request, DriverProfile $profile): array
    {
        $bookings = Booking::query()
            ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
            ->select('bookings.*')
            ->whereIn('scheduled_trips.commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $profile->user_id)
                ->select('id'))
            ->where('scheduled_trips.departure_at', '>=', now())
            ->where('bookings.cancelled_at', '>=', now()->subHours(self::DRIVER_NOTICE_HOURS))
            ->where(fn (Builder $query) => $query
                ->where('bookings.status', BookingStatus::CancelledByPassenger)
                ->orWhere(fn (Builder $exit) => $exit
                    ->where('bookings.status', BookingStatus::ExitedDueToDriverDelay)
                    ->where(fn (Builder $reason) => $reason
                        ->whereNull('bookings.cancelled_reason')
                        ->orWhere('bookings.cancelled_reason', '!=', 'trip_expired')))
                ->orWhere(fn (Builder $switch) => $switch
                    ->where('bookings.status', BookingStatus::CancelledByDriver)
                    ->where('bookings.cancelled_reason', SwitchReason::DriverDelay->value)))
            ->with(['passenger.stats', 'passenger.verifications', 'scheduledTrip'])
            ->orderByDesc('bookings.cancelled_at')
            ->orderByDesc('bookings.id')
            ->limit(self::DRIVER_NOTICES)
            ->get();

        $switches = [SwitchReason::Voluntary->value, SwitchReason::DriverDelay->value];
        $notices = [];

        foreach ($bookings as $booking) {
            $trip = $booking->scheduledTrip;

            $notices[] = [
                'kind' => in_array($booking->cancelled_reason, $switches, true) ? 'passenger_switched' : 'seat_opened',
                'bookingId' => $booking->id,
                'tripId' => $trip->id,
                'commuteId' => $trip->commute_offer_id,
                'person' => PersonSummary::for($booking->passenger, $request->user()),
                'seatsReleased' => $booking->seats_reserved,
                'seatsOpen' => (int) max(0, $trip->seats_total - $trip->seats_taken),
                'departureAt' => $trip->departure_at->toIso8601String(),
                'departureLocal' => $trip->departure_local->format('Y-m-d H:i:s'),
                'at' => $booking->cancelled_at->toIso8601String(),
            ];
        }

        return $notices;
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
            // answer is most overdue — and nothing closes a request but an answer (R-4).
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
