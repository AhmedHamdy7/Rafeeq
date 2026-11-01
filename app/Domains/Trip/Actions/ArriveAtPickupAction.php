<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\Models\TripWaitTimer;
use App\Domains\Trip\Support\TripSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "I've arrived" — for ONE passenger, checked by GPS (TRIP-10, TRIP-11; decision C14).
 *
 * 🔴 Why the server checks where the car is. The grace starts from this tap, and the lateness and
 * no-show fees start from the grace. If "arrived" were whatever the driver said, a driver could tap
 * it two kilometres away, let five minutes run out, and charge a passenger for not being at a kerb
 * the car never reached. So the arrival is refused with `409 NOT_AT_PICKUP` (and how far she still
 * is) unless the driver is within `trip.arrival_radius_meters` of THIS passenger's pickup.
 *
 * 🔴 And why the grace may start later than the arrival. A driver who turns up at 07:20 for a 07:30
 * pickup has not started the passenger's five minutes: the grace runs from the LATER of the arrival
 * and her own pickup time. Her pickup time is her stop's ETA on the run's navigation itinerary —
 * the time she was told — or, when no itinerary was built, the run's departure.
 *
 * What it does, in one transaction:
 *
 * 1. moves the run to `AT_PICKUP` if it is not there already (a run waiting at one gate may record
 *    the next passenger's arrival at the same gate without leaving the state);
 * 2. writes the arrival onto a new wait timer for her, which IS the grace clock — see
 *    `trip_wait_timers.arrived_at` / `grace_starts_at`.
 *
 * Two endpoints arrive here: `POST /trips/{trip}/status` with `AT_PICKUP`, and
 * `POST /trips/{trip}/wait-timers`. They used to mean different things — a state change with no
 * passenger in it, and a timer with no location in it — and that gap was exactly where an
 * unchecked grace could start. One Action closes it for both.
 */
final readonly class ArriveAtPickupAction
{
    public function __construct(
        private GeoQueryEngine $geo,
        private AdvanceTripAction $advance,
        private WaitTimerAction $timers,
    ) {}

    public function execute(TripSession $session, Booking $booking, Coordinate $driverAt): TripWaitTimer
    {
        $this->assertCanArrive($session);

        // Loaded once here: strict mode turns a lazy load further down into an exception.
        $booking->loadMissing('scheduledTrip.commuteOffer.locations');

        if ($this->timers->running($session, $booking->id) !== null) {
            throw DomainException::of(ErrorCode::WaitTimerAlreadyRunning);
        }

        $pickup = $this->pickupOf($booking);
        $distance = $this->geo->distanceMeters($driverAt, $pickup)->metres;
        $radius = TripSettings::arrivalRadiusMeters();

        if ($distance > $radius) {
            throw DomainException::of(ErrorCode::NotAtPickup, fields: [
                'distanceMeters' => [(string) $distance],
                'radiusMeters' => [(string) $radius],
            ]);
        }

        $arrivedAt = CarbonImmutable::now();
        $pickupAt = $this->pickupTimeOf($booking);

        $graceStartsAt = $pickupAt !== null && $pickupAt->greaterThan($arrivedAt) ? $pickupAt : $arrivedAt;

        return DB::transaction(function () use ($session, $booking, $arrivedAt, $distance, $graceStartsAt): TripWaitTimer {
            if ($session->current_status !== TripSessionStatus::AtPickup
                && $session->current_status->canTransitionTo(TripSessionStatus::AtPickup)) {
                $this->advance->execute($session, TripSessionStatus::AtPickup);
            }

            return $this->timers->start($session, $booking, $arrivedAt, $distance, $graceStartsAt);
        });
    }

    /**
     * On the road. The same refusal the wait timer always gave, so a run that has not set off (or
     * has finished) cannot have anybody's grace started on it.
     *
     * A run already IN_PROGRESS still records an arrival without changing state through the
     * wait-timer endpoint: a driver who collects somebody after setting off must still be able to
     * start her grace. (The status endpoint keeps the state machine strict and refuses
     * `in_progress → at_pickup` before it gets here.)
     */
    private function assertCanArrive(TripSession $session): void
    {
        if (! $session->current_status->isUnderway()) {
            throw DomainException::of(ErrorCode::AttendanceNotConfirmable, fields: [
                'tripStatus' => [strtoupper($session->current_status->value)],
            ]);
        }
    }

    /**
     * Where she is being collected on THIS day: the pickup snapshotted on her booking, and the
     * commute's start for the oldest bookings that never carried one.
     */
    private function pickupOf(Booking $booking): Coordinate
    {
        if ($booking->pickup_lat !== null && $booking->pickup_lng !== null) {
            return new Coordinate((float) $booking->pickup_lat, (float) $booking->pickup_lng);
        }

        $origin = $booking->scheduledTrip->commuteOffer->locations
            ->firstWhere('type', CommuteLocationType::Origin);

        if ($origin === null) {
            // A booking with nowhere to be collected cannot be arrived at; a refusal, not a 500.
            throw DomainException::of(ErrorCode::NotAtPickup, fields: ['distanceMeters' => []]);
        }

        return new Coordinate((float) $origin->lat, (float) $origin->lng);
    }

    /**
     * The time she was told to be ready: the run's departure plus the driving time to her pickup
     * along this run's itinerary (the legs up to and including hers), or the departure itself when
     * no itinerary was built.
     *
     * 🔴 Counted from `departure_at` rather than read from the stored `eta_at`, because the departure
     * moves (C-2: recomputed as riders join or leave, until the 16 h lock) and an ETA written before
     * the move would start her grace at a time nobody told her any more.
     */
    private function pickupTimeOf(Booking $booking): ?CarbonImmutable
    {
        $departure = $booking->scheduledTrip->departure_at;

        if ($departure === null) {
            return null;
        }

        $stops = NavigationStop::query()
            ->where('scheduled_trip_id', $booking->scheduled_trip_id)
            ->orderBy('sequence')
            ->get(['booking_id', 'kind', 'sequence', 'leg_duration_seconds']);

        $offset = 0;

        foreach ($stops as $stop) {
            $offset += (int) $stop->leg_duration_seconds;

            if ($stop->booking_id === $booking->id && $stop->kind === NavigationStopKind::PassengerPickup) {
                return CarbonImmutable::instance($departure)->addSeconds($offset);
            }
        }

        return CarbonImmutable::instance($departure);
    }
}
