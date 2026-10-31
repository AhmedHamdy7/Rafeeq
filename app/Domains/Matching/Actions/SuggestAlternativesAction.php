<?php

namespace App\Domains\Matching\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Matching\Support\AlternativeOrigin;
use App\Domains\Matching\Support\MatchResult;
use App\Domains\Matching\Support\HardFilters;
use App\Domains\Matching\Support\SearchCriteria;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Shared\ValueObjects\WalkTime;
use App\Domains\Trip\Actions\RecordTripLocationAction;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\NavigationStop;
use App\Http\Controllers\Api\V1\Booking\BookingController;
use Carbon\CarbonImmutable;

/**
 * "Find another commute": what else she could still catch, after a morning went wrong
 * (Feature 03 v5 §126–128, §441, §444).
 *
 * > *"When active trip canceled, offer **suggestions** of compatible nearby alternatives, **never
 * > automatically book**."* · *"For already-boarded passengers: alternatives start at actual
 * > stop/current termination location and continue to remaining destination. For confirmed
 * > passengers not yet picked up: alternatives start at originally agreed pickup."*
 *
 * 🔴 **It books nothing and reserves nothing.** It is a search with the question filled in, and
 * what she does with an answer is ask for a seat like anybody else. v5 says so twice, and the
 * reason is not procedural: a woman whose ride just collapsed is the last person who should
 * discover she has been committed to a stranger's car on her behalf.
 *
 * 🔴 **The whole value is that the server knows where she is**, so she does not retype a journey
 * while standing at a roadside. Which origin to search from is read from what the run recorded, in
 * the order v5 implies and this codebase can actually witness — see {@see origin()}. It is NOT
 * asked for: v5 §129 is explicit that there is to be no safe-stop picker, and a form asking "where
 * are you?" is that picker with a different label.
 *
 * ⚠️ **Rafeeq does not dispatch, and this does not pretend otherwise.** The underlying search only
 * returns occurrences that have not departed, so for a commute stopped at 08:20 the honest answer
 * is often tomorrow's run rather than a car five minutes away. That is what planned commuting makes
 * possible; a list padded with runs she cannot reach would be a worse answer, not a kinder one.
 *
 * ⚠️ Not used for her OWN cancellation before pickup either way — she has the full search for that,
 * with her real preferences in it. This exists for the journeys that were taken away from her, and
 * {@see BookingController::alternatives} says which those are.
 */
final readonly class SuggestAlternativesAction
{
    public function __construct(
        private SearchCommutesAction $search,
        private RecordTripLocationAction $locations,
    ) {}

    /**
     * @return array{origin: AlternativeOrigin, results: array<int, MatchResult>}
     */
    public function execute(Booking $booking): array
    {
        $trip = $booking->scheduledTrip
            ?? throw DomainException::of(ErrorCode::NotFound);

        $destination = $this->destination($booking);

        if ($destination === null) {
            /*
             * Her agreed drop-off is the one thing that cannot be inferred from anywhere else: a
             * search without a destination would rank every car heading vaguely her way. Bookings
             * made before the snapshot columns existed can be missing it (see the migration that
             * backfilled them), and for those the honest answer is that we cannot suggest.
             */
            throw DomainException::of(ErrorCode::NotFound);
        }

        $origin = $this->origin($booking, $trip);

        $results = $this->search->execute($booking->passenger, new SearchCriteria(
            origin: $origin->point,
            destination: $destination,
            /*
             * This weekday only. She needs a lift on the day she lost one, and widening it to her
             * whole pattern would fill the list with Thursdays while she is standing in the road on
             * a Monday. A commute that runs today and whose next occurrence is next Monday still
             * appears — the search filters on the occurrence, not on the mask alone.
             */
            days: DaysMask::fromBits(DaysMask::bitForDate($trip->trip_date)),
            /*
             * From now, forward. Not from her agreed departure: that moment has passed, and a
             * window centred on it would score the runs she has already missed highest.
             */
            arrivalWindowStart: $this->now($trip)->format('H:i:s'),
            arrivalWindowEnd: $this->now($trip)
                ->addMinutes((int) config('rafeeq.matching.alternatives.window_minutes'))
                ->format('H:i:s'),
            maxWalk: WalkTime::fromMinutes((int) config('rafeeq.matching.alternatives.max_walk_minutes')),
            maxDetourMinutes: (int) config('rafeeq.matching.alternatives.max_detour_minutes'),
            seatsNeeded: max(1, (int) $booking->seats_reserved),
            /*
             * Carried over from the commute she lost, as a PREFERENCE rather than a filter. A woman
             * who chose a women-only commute should see women-only ones first; making it a filter
             * would hide every other option from somebody who may need any lift at all. Who may
             * ask on a women-only commute is checked on the results below.
             */
            audiencePreference: $trip->commuteOffer?->audience,
        ));

        /*
         * Only commutes she may actually ask on. Search shows women-only commutes to everybody now
         * (Feature 03 C-11), but this list is "where to ask for a lift right now", and a commute
         * that would refuse her request is not an alternative.
         */
        $askable = [];

        foreach ($results as $result) {
            if (HardFilters::mayJoinAudience($booking->passenger, $result->offer)) {
                $askable[] = $result;
            }
        }

        return ['origin' => $origin, 'results' => $askable];
    }

    /**
     * Where to search from, and why — which the client needs as much as the coordinates, because
     * the two cases are different screens: one says "other commutes from here", the other "other
     * commutes from your usual stop".
     *
     * 🔴 The order is "what the run actually recorded", narrowing to her agreed point only when
     * nothing was recorded. v5 §129 asks for exactly this and names the failure to avoid: *"handle
     * unavailable location gracefully without inventing another mandatory step."* So every step
     * below ends in an answer, and none of them ends in a question.
     *
     * 1. **She was never collected** → her agreed pickup. Nothing about the car's position is
     *    relevant: she is standing where she always stands.
     * 2. **She was aboard and the car's position is known** → that position. This is the case v5
     *    means by "actual stop/current termination location".
     * 3. **She was aboard and it is not** → the last stop the run is recorded as having reached,
     *    which is the furthest point it is known to have got to. Positions are buffered and expire;
     *    the itinerary does not.
     * 4. **Nothing at all** → her agreed pickup, which is wrong by however far the car carried her
     *    and is still better than refusing to answer. The response says which case it was, so a
     *    client can offer her the map rather than pretend.
     */
    private function origin(Booking $booking, ScheduledTrip $trip): AlternativeOrigin
    {
        $agreed = new Coordinate((float) $booking->pickup_lat, (float) $booking->pickup_lng);

        if (! $this->wasAboard($booking)) {
            return new AlternativeOrigin($agreed, AlternativeOrigin::AGREED_PICKUP, $booking->pickup_label);
        }

        $session = $trip->tripSession;

        $position = $session === null ? null : $this->locations->current($session);

        if ($position !== null) {
            return new AlternativeOrigin(
                new Coordinate($position->lat, $position->lng),
                AlternativeOrigin::WHERE_THE_CAR_STOPPED,
            );
        }

        $reached = NavigationStop::query()
            ->where('scheduled_trip_id', $trip->getKey())
            ->whereNotNull('reached_at')
            ->whereNotNull('lat')
            ->where('kind', '!=', NavigationStopKind::DriverEnd->value)
            ->orderByDesc('reached_at')
            // Ordering on one timestamp is not a total order — several stops can be closed in the
            // same second, as they are when a journey ends — so the id settles it (standard #42).
            ->orderByDesc('id')
            ->first();

        if ($reached !== null) {
            return new AlternativeOrigin(
                new Coordinate((float) $reached->lat, (float) $reached->lng),
                AlternativeOrigin::LAST_STOP_REACHED,
                $reached->label,
            );
        }

        return new AlternativeOrigin($agreed, AlternativeOrigin::AGREED_PICKUP_UNVERIFIED, $booking->pickup_label);
    }

    /**
     * Whether she had been collected. Read from attendance rather than from the booking's status,
     * because the status says what was decided about her seat and this asks where her body is.
     */
    private function wasAboard(Booking $booking): bool
    {
        $attendance = Attendance::query()->whereKey($booking->getKey())->first();

        return $attendance !== null && $attendance->status->travelled();
    }

    /**
     * Her agreed drop-off: the one point in this search that is hers rather than the car's. Where
     * she was going does not change because the journey stopped.
     */
    private function destination(Booking $booking): ?Coordinate
    {
        if ($booking->dropoff_lat !== null && $booking->dropoff_lng !== null) {
            return new Coordinate((float) $booking->dropoff_lat, (float) $booking->dropoff_lng);
        }

        return null;
    }

    /**
     * Now, on the clock she reads — the same local wall clock the commute's own departure is stored
     * on. Using UTC here would shift her window by the offset and score every result wrongly.
     */
    private function now(ScheduledTrip $trip): CarbonImmutable
    {
        return CarbonImmutable::now($trip->commuteSchedule?->timezone ?? config('app.timezone'));
    }
}
