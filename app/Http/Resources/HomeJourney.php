<?php

namespace App\Http\Resources;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Enums\GroupAttendanceStatus;
use App\Domains\Rating\Actions\SubmitRatingAction;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Trip\Models\TripWaitTimer;
use App\Domains\Trip\Support\DriverDelay;
use App\Domains\Trip\Support\LiveStopEtas;
use App\Domains\Trip\Support\PickupTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The parts of screen 9's journey card that change with the day: which state it is in, where and
 * when she is collected, how far the car has got, and what happened if it ended (F3P-02 → F3P-11).
 *
 * 🔴 Why `phase` is computed here rather than left to the app. The card is one of eleven states,
 * and which one is a function of the booking's status, the run's status, her attendance row, the
 * driver's arrival timer and the clock. Rebuilt on a phone, that is five things to keep in step
 * with the server's own rules — and the rules move: an expired morning is recorded as an EXIT on
 * her booking (`exited_due_to_driver_delay` with reason `trip_expired`), which a client reading the
 * status alone would show as "you left", when in truth nobody came.
 *
 * 🔴 And every value is READ from what the run recorded. Nothing here estimates where the car is:
 * - "driver arrived" is the driver's GPS-checked arrival (`trip_wait_timers.arrived_at`, TRIP-10);
 * - "on board" is her check-in (`attendance.checked_in_at`, by code or by the driver);
 * - "exited" is her booking's status (`POST /bookings/{booking}/exit-without-fee`, or the sweep);
 * - the ETAs are the itinerary's own measured leg durations, counted on from the last stop the car
 *   actually reached — v5's "Do not create fake distance/duration/ETA values" is the reason there
 *   is no ETA at all when no itinerary was built, rather than a guess.
 *
 * Read-only, like the rest of Home.
 */
final class HomeJourney
{
    public const string SCHEDULED = 'scheduled';

    public const string DRIVER_EN_ROUTE = 'driver_en_route';

    public const string DRIVER_ARRIVED = 'driver_arrived';

    public const string ON_BOARD = 'on_board';

    public const string DRIVER_DELAYED = 'driver_delayed';

    public const string CANCELLED_BY_DRIVER = 'cancelled_by_driver';

    public const string EXPIRED = 'expired';

    public const string EXITED = 'exited';

    public const string COMPLETED = 'completed';

    /** The phases that ended before anybody rode — the ones the card offers alternatives on. */
    public const array ENDED = [self::CANCELLED_BY_DRIVER, self::EXPIRED, self::EXITED];

    /** The phases where the car is on the road for her, and the app polls. */
    public const array LIVE = [self::DRIVER_EN_ROUTE, self::DRIVER_ARRIVED, self::ON_BOARD];

    /**
     * Never quite 100 until the car is there: a full bar on a car still two minutes away would be
     * the card claiming an arrival nothing recorded.
     */
    private const int PROGRESS_CEILING = 99;

    /** @var Collection<int, NavigationStop> */
    private Collection $stops;

    private ?Attendance $attendance;

    private ?CarbonImmutable $arrivedAt;

    private function __construct(
        private readonly Booking $booking,
        private readonly ScheduledTrip $trip,
        private readonly SubmitRatingAction $ratings,
        private readonly string $viewerId,
    ) {
        $this->stops = NavigationStop::query()
            ->where('scheduled_trip_id', $trip->getKey())
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['id', 'scheduled_trip_id', 'booking_id', 'kind', 'sequence', 'leg_duration_seconds', 'reached_at', 'eta_at', 'lat', 'lng']);

        $this->attendance = Attendance::query()->whereKey($booking->getKey())->first();

        /*
         * The latest arrival for her on this run. Latest rather than any, because an arrival can be
         * recorded twice — once at the gate and again after the car went round the block — and the
         * card means the one the car is standing at now.
         */
        $timer = $trip->tripSession === null ? null : TripWaitTimer::query()
            ->where('trip_session_id', $trip->tripSession->getKey())
            ->where('booking_id', $booking->getKey())
            ->whereNotNull('arrived_at')
            ->latest('arrived_at')
            ->orderByDesc('id')
            ->first();

        $this->arrivedAt = $timer?->arrived_at === null ? null : CarbonImmutable::instance($timer->arrived_at);
    }

    public static function for(Booking $booking, SubmitRatingAction $ratings, string $viewerId): self
    {
        return new self($booking, $booking->scheduledTrip, $ratings, $viewerId);
    }

    /**
     * The card state. See the class note for where each one is read from.
     */
    public function phase(): string
    {
        return match ($this->booking->status) {
            BookingStatus::CancelledByDriver => self::CANCELLED_BY_DRIVER,
            /*
             * One status, two stories: she pressed Leave Without Fee, or the sweep closed a morning
             * the driver never started. The second is not her leaving and must not read as it.
             */
            BookingStatus::ExitedDueToDriverDelay => $this->trip->status === ScheduledTripStatus::Expired
                || $this->booking->cancelled_reason === 'trip_expired'
                    ? self::EXPIRED
                    : self::EXITED,
            BookingStatus::Completed => self::COMPLETED,
            BookingStatus::Confirmed => $this->confirmedPhase(),
            default => self::SCHEDULED,
        };
    }

    private function confirmedPhase(): string
    {
        if ($this->isOnBoard()) {
            return self::ON_BOARD;
        }

        $session = $this->trip->tripSession;

        if ($session !== null && $session->started_at !== null && ! $session->current_status->isTerminal()) {
            if ($this->arrivedAt !== null) {
                return self::DRIVER_ARRIVED;
            }

            return DriverDelay::entitlesFreeExit($this->booking, $this->trip)
                ? self::DRIVER_DELAYED
                : self::DRIVER_EN_ROUTE;
        }

        /*
         * The same test the no-fee exit uses, so "driver delayed" appears on the card at exactly the
         * moment the Leave Without Fee button would be honoured — never a minute before.
         */
        if (DriverDelay::neverSetOff($this->trip)) {
            return self::DRIVER_DELAYED;
        }

        return self::SCHEDULED;
    }

    private function isOnBoard(): bool
    {
        return $this->attendance !== null
            && $this->attendance->status->travelled()
            && $this->attendance->checked_in_at !== null
            && $this->attendance->checked_out_at === null;
    }

    /**
     * "Your agreed pickup · Rehab Gate 2 · 07:05". The place under the same rule as `meetingPoint`
     * — exact only once confirmed — plus the time she was told to be ready, which is not the
     * departure: the car leaves at 07:00 and reaches her stop at 07:05.
     *
     * @param  array<string, mixed>|null  $meetingPoint  the booking resource's own `meetingPoint`
     * @return array{label: string|null, at: string|null, local: string|null, lat: float|null, lng: float|null, isExact: bool}
     */
    public function pickup(?array $meetingPoint, ?string $originLabel): array
    {
        $at = PickupTime::fromStops($this->booking, $this->stops);

        return [
            // Null until confirmed, like `meetingPoint.label`; the run's public origin otherwise.
            'label' => $meetingPoint === null ? $originLabel : ($meetingPoint['label'] ?? null),
            'at' => $at?->toIso8601String(),
            'local' => $at === null ? null : $this->local($at),
            'lat' => $meetingPoint === null ? null : (float) $meetingPoint['lat'],
            'lng' => $meetingPoint === null ? null : (float) $meetingPoint['lng'],
            'isExact' => $meetingPoint !== null && (bool) $meetingPoint['isExact'],
        ];
    }

    /**
     * "Drop-off · promised arrival · Smart Village B6 · 08:10". The promise is the DAY's
     * (`scheduled_trips.promised_arrival_at`), which is what the people on board were told and what
     * a new rider may not push past; null when the driver promised no arrival for that day.
     *
     * @param  array<string, mixed>|null  $dropoffPoint  the booking resource's own `dropoffPoint`
     * @return array{label: string|null, lat: float|null, lng: float|null, isExact: bool, promisedArrivalAt: string|null, promisedArrivalLocal: string|null}
     */
    public function dropoff(?array $dropoffPoint, ?string $destinationLabel): array
    {
        $promised = $this->trip->promised_arrival_at;

        return [
            'label' => $dropoffPoint['label'] ?? $destinationLabel,
            'lat' => $dropoffPoint === null ? null : (float) $dropoffPoint['lat'],
            'lng' => $dropoffPoint === null ? null : (float) $dropoffPoint['lng'],
            'isExact' => $dropoffPoint !== null && (bool) $dropoffPoint['isExact'],
            'promisedArrivalAt' => $promised?->toIso8601String(),
            'promisedArrivalLocal' => $promised === null ? null : $this->local(CarbonImmutable::instance($promised)),
        ];
    }

    /**
     * F3P-04 → F3P-06. Null outside those three, which is what "before the driver starts" means.
     *
     * @return array{pickupEtaAt: string|null, arrivalEtaAt: string|null, progressPercent: int|null, driverArrivedAt: string|null, boardedAt: string|null, stopsBeforeYou: int|null}|null
     */
    public function live(): ?array
    {
        $phase = $this->phase();

        if ($phase === self::DRIVER_DELAYED && $this->trip->tripSession?->started_at !== null) {
            $phase = self::DRIVER_EN_ROUTE;
        }

        if (! in_array($phase, self::LIVE, true)) {
            return null;
        }

        $now = CarbonImmutable::now();
        $boardedAt = $this->attendance?->checked_in_at === null
            ? null
            : CarbonImmutable::instance($this->attendance->checked_in_at);
        $startedAt = $this->trip->tripSession?->started_at === null
            ? null
            : CarbonImmutable::instance($this->trip->tripSession->started_at);

        $pickupEta = null;
        $arrivalEta = null;
        $progress = null;
        $stopsBefore = null;

        if ($phase === self::DRIVER_EN_ROUTE) {
            $stopsBefore = $this->stopsBeforePickup();
            $pickupEta = $this->liveEtaTo(NavigationStopKind::PassengerPickup)
                ?? $this->etaTo(NavigationStopKind::PassengerPickup, $startedAt, $now);
            $progress = $this->progress($startedAt, $pickupEta, $now);
        } elseif ($phase === self::DRIVER_ARRIVED) {
            // The car is at her kerb: the bar to her pickup is full, and that is recorded, not guessed.
            $progress = 100;
            $stopsBefore = 0;
        } else {
            $arrivalEta = $this->etaTo(NavigationStopKind::PassengerDropoff, $boardedAt, $now);
            $progress = $this->progress($boardedAt, $arrivalEta, $now);
        }

        return [
            'pickupEtaAt' => $pickupEta?->toIso8601String(),
            'arrivalEtaAt' => $arrivalEta?->toIso8601String(),
            'progressPercent' => $progress,
            'driverArrivedAt' => $this->arrivedAt?->toIso8601String(),
            'boardedAt' => $phase === self::ON_BOARD ? $boardedAt?->toIso8601String() : null,
            'stopsBeforeYou' => $stopsBefore,
        ];
    }

    private function stopsBeforePickup(): ?int
    {
        $hers = $this->stops->first(fn (NavigationStop $stop): bool => $stop->booking_id === $this->booking->getKey()
            && $stop->kind === NavigationStopKind::PassengerPickup);

        if ($hers === null || $hers->reached_at !== null) {
            return null;
        }

        return LiveStopEtas::stopsBefore($this->stops, $hers);
    }

    /**
     * When the car should reach her stop of this kind, counted on from where it actually is.
     *
     * The last stop BEFORE hers that the car is recorded as having reached, plus the measured
     * durations of the legs still between that stop and hers. Before any stop is reached, counted
     * from the moment the run started (or she boarded, for the drop-off).
     *
     * ⚠️ Never in the past. A car running late past its own estimate has not arrived; saying "ETA
     * 07:04" at 07:09 would be the card asserting something the run disproves. It reads "now" until
     * the car gets there, which is the truthful version of "any moment".
     */
    private function liveEtaTo(NavigationStopKind $kind): ?CarbonImmutable
    {
        $target = $this->stops->first(fn (NavigationStop $stop): bool => $stop->booking_id === $this->booking->getKey()
            && $stop->kind === $kind);

        if ($target === null || $target->reached_at !== null) {
            return null;
        }

        $live = LiveStopEtas::for($this->trip, $this->stops);

        return $live->isLive ? $live->etaFor($target) : null;
    }

    private function etaTo(NavigationStopKind $kind, ?CarbonImmutable $since, CarbonImmutable $now): ?CarbonImmutable
    {
        $target = $this->stops->first(fn (NavigationStop $stop): bool => $stop->booking_id === $this->booking->getKey()
            && $stop->kind === $kind);

        if ($target === null) {
            return null;
        }

        $reached = $this->stops
            ->filter(fn (NavigationStop $stop): bool => $stop->sequence < $target->sequence && $stop->reached_at !== null)
            ->sortByDesc('sequence')
            ->first();

        $base = $reached?->reached_at !== null ? CarbonImmutable::instance($reached->reached_at) : $since;

        if ($base === null) {
            return null;
        }

        $fromSequence = $reached?->sequence ?? -1;

        $remaining = (int) $this->stops
            ->filter(fn (NavigationStop $stop): bool => $stop->sequence > $fromSequence && $stop->sequence <= $target->sequence)
            ->sum('leg_duration_seconds');

        $eta = $base->addSeconds($remaining);

        return $eta->lessThan($now) ? $now : $eta;
    }

    /**
     * Time elapsed over time expected, on the same clock as the ETA beside it, so the bar and the
     * "ETA 07:04" can never disagree. Null when either end is unknown.
     */
    private function progress(?CarbonImmutable $from, ?CarbonImmutable $eta, CarbonImmutable $now): ?int
    {
        if ($from === null || $eta === null) {
            return null;
        }

        $expected = $from->diffInSeconds($eta, absolute: false);

        if ($expected <= 0) {
            return self::PROGRESS_CEILING;
        }

        $elapsed = max(0, $from->diffInSeconds($now, absolute: false));

        return (int) min(self::PROGRESS_CEILING, floor($elapsed / $expected * 100));
    }

    /**
     * @return array{groupId: string|null, answer: string|null, cutoffAt: string|null, canAnswer: bool}|null
     */
    public function checkIn(): ?array
    {
        if ($this->booking->status !== BookingStatus::Confirmed) {
            return null;
        }

        $group = $this->booking->commuteGroup;
        $declared = $group?->attendances
            ->where('scheduled_trip_id', $this->trip->getKey())
            ->firstWhere('user_id', $this->viewerId);
        $answer = $declared?->status === GroupAttendanceStatus::NoResponse ? null : $declared?->status->value;

        $cutoff = $this->trip->departure_at === null
            ? null
            : CarbonImmutable::instance($this->trip->departure_at)
                ->subHours((int) config('rafeeq.group.attendance_cutoff_hours'));

        return [
            'groupId' => $group === null ? null : (string) $group->getKey(),
            'answer' => $answer,
            'cutoffAt' => $cutoff?->toIso8601String(),
            'canAnswer' => $group !== null
                && $cutoff !== null
                && $cutoff->isFuture()
                && $this->trip->status === ScheduledTripStatus::Scheduled,
        ];
    }

    /**
     * F3P-07's Leave Without Fee. The SAME finding `POST /bookings/{booking}/exit-without-fee`
     * makes before it acts, so the button is never shown when the server would refuse it — and
     * never hidden when she is entitled (a car that drove past her stop counts too, mid-run).
     */
    public function canLeaveWithoutFee(): bool
    {
        return $this->booking->status === BookingStatus::Confirmed
            && ! $this->isOnBoard()
            && DriverDelay::entitlesFreeExit($this->booking, $this->trip);
    }

    /**
     * When the day was taken away from her: the cancellation, the exit, or the sweep that closed it.
     * All three write `cancelled_at` on her booking, so one column answers all three.
     */
    public function endedAt(): ?string
    {
        return in_array($this->phase(), self::ENDED, true)
            ? $this->booking->cancelled_at?->toIso8601String()
            : null;
    }

    /**
     * "Ride completed · 08:07": when she was set down, which is her own check-out — and the run's
     * end for a check-out that was never recorded separately.
     */
    public function completedAt(): ?CarbonInterface
    {
        if ($this->booking->status !== BookingStatus::Completed) {
            return null;
        }

        return $this->attendance?->checked_out_at ?? $this->trip->tripSession?->completed_at;
    }

    /**
     * Rate Nour. The same two tests `GET /ratings/pending` applies — not yet rated by her, window
     * still open — so the button and the pending list cannot disagree.
     */
    public function canRate(): bool
    {
        if ($this->booking->status !== BookingStatus::Completed) {
            return false;
        }

        if ($this->booking->ratings()->where('reviewer_user_id', $this->viewerId)->exists()) {
            return false;
        }

        return ! $this->ratings->deadlineFor($this->booking)->isPast();
    }

    /**
     * The bitmask of the days she rides (Sat 1 … Fri 64): the days she asked for, or every day the
     * commute runs when the request named none.
     */
    public function daysMask(): int
    {
        return (int) ($this->booking->seatRequest?->requested_days_mask
            ?? $this->trip->commuteSchedule?->days_mask
            ?? 0);
    }

    /**
     * Display wall clock, in the commute's own timezone — the same convention as `departureLocal`.
     */
    private function local(CarbonImmutable $at): string
    {
        return $at->setTimezone($this->trip->commuteSchedule?->timezone ?? config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }
}
